<?php

namespace iikiti\CMS\Audit;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AuditLogEntry;
use iikiti\CMS\Entity\Object\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Low-level writer for audit log rows.
 *
 * Plugins and core code should use {@see AuditRecorder}, which enforces a
 * human-readable summary. This class handles persistence, request context and
 * grouping: while an event is open on {@see AuditContext}, entries are buffered
 * as sub-events of that event instead of being written as top-level rows.
 */
class AuditLogger
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly TokenStorageInterface $tokenStorage,
		private readonly RequestStack $requestStack,
		private readonly AuditContext $context,
		private readonly LoggerInterface $logger,
		#[Autowire('%kernel.environment%')]
		private readonly string $environment = 'prod',
	) {
	}

	/**
	 * Records an action. Inside an open event it becomes a sub-event; outside
	 * one it is persisted as its own top-level event with the given summary.
	 *
	 * @param array<string,mixed>|null $beforeState
	 * @param array<string,mixed>|null $afterState
	 * @param array<string,mixed>      $extraContext
	 */
	public function log(
		string $action,
		string $objectType,
		int|string|null $objectId = null,
		?array $beforeState = null,
		?array $afterState = null,
		string $actorType = 'user',
		array $extraContext = [],
		bool $flush = true,
		?string $summary = null,
	): void {
		$entry = $this->buildEntry($action, $objectType, $objectId, $beforeState, $afterState, $actorType, $extraContext, $summary);

		if ($this->context->isOpen()) {
			// Buffered, not persisted: the parent must be managed before its children.
			$entry->setParent($this->context->getParent());
			$this->context->addSubEvent($entry);

			return;
		}

		$entry->setSummary($this->resolveSummary($summary, $action, $objectType, $extraContext));
		$this->entityManager->persist($entry);

		if ($flush) {
			$this->entityManager->flush();
		}
	}

	/**
	 * Opens a top-level event for the current request or command.
	 *
	 * @param array<string,mixed> $extraContext
	 */
	public function openEvent(string $action, string $summary, array $extraContext = []): void
	{
		$current = $this->context->getParent();

		// A named event (login, logout) supersedes the generic request/command
		// event opened for the same request, so the request is recorded once,
		// under its meaningful name. Generic events never displace an open one.
		if (null !== $current && !$this->isGenericAction($current->getAction())) {
			return;
		}

		$event = $this->buildEntry($action, 'Event', null, null, null, 'user', $extraContext, $summary);
		$event->setSummary($summary);

		// Keep changes already recorded for this request: re-parent them under the
		// named event so nothing buffered is lost when it takes over.
		$carried = null === $current ? [] : $this->context->takeSubEvents();
		$this->context->open($event);
		foreach ($carried as $subEvent) {
			$subEvent->setParent($event);
			$this->context->addSubEvent($subEvent);
		}
	}

	private function isGenericAction(string $action): bool
	{
		return in_array($action, ['request', 'command'], true);
	}

	/**
	 * Closes the open event. It is persisted together with its sub-events only
	 * when at least one sub-event exists; otherwise nothing is written.
	 *
	 * @param bool $flush Whether to flush immediately (pass false inside onFlush)
	 */
	public function closeEvent(bool $flush = true): void
	{
		[$parent, $subEvents] = $this->context->close();

		if (null === $parent || [] === $subEvents) {
			return;
		}

		$this->entityManager->persist($parent);
		foreach ($subEvents as $subEvent) {
			$this->entityManager->persist($subEvent);
		}

		if ($flush) {
			$this->entityManager->flush();
		}
	}

	/**
	 * @param array<string,mixed>|null $beforeState
	 * @param array<string,mixed>|null $afterState
	 * @param array<string,mixed>      $extraContext
	 */
	private function buildEntry(
		string $action,
		string $objectType,
		int|string|null $objectId,
		?array $beforeState,
		?array $afterState,
		string $actorType,
		array $extraContext,
		?string $summary,
	): AuditLogEntry {
		$user = $this->resolveUser();

		$context = array_merge(['environment' => $this->environment], $extraContext);

		if ('dev' === $this->environment || 'test' === $this->environment) {
			$context['debug'] = true;
			$context['debug_info'] = [
				'trace' => $this->getCallerInfo(),
				'request_context' => $this->getRequestContext(),
			];
		}

		$entry = new AuditLogEntry();
		$entry->setAction($action);
		$entry->setObjectType($objectType);
		$entry->setObjectId($objectId);
		$entry->setBeforeState($beforeState);
		$entry->setAfterState($afterState);
		$entry->setContext($context);
		$entry->setActorType($actorType);
		$entry->setSummary($summary);

		if (null !== $user) {
			$entry->setUser($user);
			$entry->setUsernameSnapshot($user->getUserIdentifier());
		}

		$request = $this->requestStack->getCurrentRequest();
		if (null !== $request) {
			$entry->setIpAddress($request->getClientIp());
			$entry->setUserAgent($request->headers->get('User-Agent'));
			$entry->setRequestUri($request->getRequestUri());
		}

		return $entry;
	}

	/**
	 * Returns the supplied summary, or a generated fallback with a warning that
	 * names the caller responsible. {@see AuditRecorder} rejects empty summaries,
	 * so reaching the fallback means code bypassed the public API.
	 *
	 * @param array<string,mixed> $extraContext
	 */
	private function resolveSummary(?string $summary, string $action, string $objectType, array $extraContext): string
	{
		if (null !== $summary && '' !== trim($summary)) {
			return $summary;
		}

		$this->logger->warning('Audit event recorded without a human-readable summary.', [
			'action' => $action,
			'objectType' => $objectType,
			'source' => $this->findResponsibleCaller(),
			'extraContext' => array_intersect_key($extraContext, ['source' => true]),
		]);

		return sprintf('%s %s', ucfirst(str_replace('_', ' ', $action)), $objectType);
	}

	/**
	 * Finds the first frame outside the audit/recording services, so the warning
	 * points at the plugin or class that skipped the public API.
	 */
	private function findResponsibleCaller(): string
	{
		foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
			$class = $frame['class'] ?? '';
			if ('' === $class || str_starts_with($class, 'iikiti\\CMS\\Audit\\')) {
				continue;
			}

			return sprintf('%s::%s (%s:%d)', $class, $frame['function'] ?? '?', $frame['file'] ?? '?', $frame['line'] ?? 0);
		}

		return 'unknown';
	}

	private function resolveUser(): ?User
	{
		$token = $this->tokenStorage->getToken();
		if (null === $token) {
			return null;
		}

		$user = $token->getUser();

		return $user instanceof User ? $user : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function getRequestContext(): ?array
	{
		$request = $this->requestStack->getCurrentRequest();
		if (null === $request) {
			return null;
		}

		return [
			'method' => $request->getMethod(),
			'headers' => 'dev' === $this->environment ? $request->headers->all() : [],
			'query' => $request->query->all(),
		];
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function getCallerInfo(): ?array
	{
		$trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 4);

		return $trace[2] ?? null;
	}
}
