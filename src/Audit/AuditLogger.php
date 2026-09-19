<?php

namespace iikiti\CMS\Audit;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AuditLogEntry;
use iikiti\CMS\Entity\Object\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Logs administrative and system actions for audit purposes.
 *
 * Captures who did what, on what object, with before/after state and
 * request context (IP, user agent, URI). In debug mode, extended context
 * is included for deeper inspection.
 */
class AuditLogger
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly TokenStorageInterface $tokenStorage,
		private readonly RequestStack $requestStack,
		#[Autowire('%kernel.environment%')]
		private readonly string $environment = 'prod',
	) {
	}

	/**
	 * Record an audit log entry.
	 *
	 * @param string             $action       One of {@see AuditLogEntry::ACTIONS}
	 * @param string             $objectType   Short class name or logical type
	 * @param int|string|null    $objectId     Entity ID when applicable
	 * @param array<string,mixed>|null $beforeState  Snapshot before the action
	 * @param array<string,mixed>|null $afterState   Snapshot after the action
	 * @param string             $actorType    'user' or 'system'
	 * @param array<string,mixed> $extraContext  Additional context data
	 */
	public function log(
		string $action,
		string $objectType,
		int|string|null $objectId = null,
		?array $beforeState = null,
		?array $afterState = null,
		string $actorType = 'user',
		array $extraContext = [],
	): void {
		$user = $this->resolveUser();

		$context = array_merge(
			[
				'environment' => $this->environment,
			],
			$extraContext,
		);

		if ($this->environment === 'dev' || $this->environment === 'test') {
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

		if (null !== $user) {
			$entry->setUser($user);
		}

		$request = $this->requestStack->getCurrentRequest();
		if (null !== $request) {
			$entry->setIpAddress($request->getClientIp());
			$entry->setUserAgent($request->headers->get('User-Agent'));
			$entry->setRequestUri($request->getRequestUri());
		}

		$this->entityManager->persist($entry);
		$this->entityManager->flush();
	}

	/**
	 * @param array<string,mixed>|null $afterState
	 * @param array<string,mixed>      $extraContext
	 */
	public function logSystemAction(
		string $action,
		string $objectType,
		int|string|null $objectId = null,
		?array $afterState = null,
		array $extraContext = [],
	): void {
		$this->log($action, $objectType, $objectId, null, $afterState, 'system', $extraContext);
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
			'headers' => $this->environment === 'dev'
				? $request->headers->all()
				: [],
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
