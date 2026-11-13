<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use iikiti\CMS\Registry\SiteRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class FirewallRequestSubscriber implements EventSubscriberInterface
{
	// Keep the policy/status control plane recoverable; inherited global rules still run.
	private const SITE_POLICY_OPERATIONS = [
		'site_firewall_policy_get',
		'site_firewall_policy_put',
		'admin_firewall_pack_status',
		'admin_firewall_pack_activate',
	];

	public function __construct(
		private FirewallRequestContextFactory $contexts,
		private FirewallRuleSetResolver $rules,
		private FirewallRuleEvaluator $evaluator,
		private EventDispatcherInterface $eventDispatcher,
		private LoggerInterface $logger,
	) {
	}

	public static function getSubscribedEvents(): array
	{
		// Run after routing/site initialization (32/30) but before Symfony Security (8).
		return [KernelEvents::REQUEST => ['onKernelRequest', 9]];
	}

	public function onKernelRequest(RequestEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$request = $event->getRequest();
		$site = SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null;
		if (null === $site && !$this->isApiRequest($request->getPathInfo())) {
			return;
		}
		$siteId = $site?->getId();
		try {
			$operation = $request->attributes->get('_api_operation_name');
			$includeSiteRules = !is_string($operation) || !in_array($operation, self::SITE_POLICY_OPERATIONS, true);
			$rules = null === $site
				? $this->rules->getGlobalRules()
				: $this->rules->getRulesForSite($site, $includeSiteRules);
			$context = $this->contexts->create($request, $siteId);
			$result = $this->evaluator->evaluate($rules, $context);
		} catch (FirewallInspectionException $exception) {
			$this->logger->warning('Firewall could not inspect the complete request.', [
				'site_id' => $siteId,
				'method' => $request->getMethod(),
				'path' => $request->getPathInfo(),
				'reason' => $exception->getMessage(),
			]);
			$this->dispatchSecurityEvent(
				[new FirewallFinding('core:inspection-limit', 'Request could not be fully inspected.', FirewallAction::Deny)],
				FirewallAction::Deny,
				$this->requestSummary($request, $siteId),
				$siteId,
			);
			$event->setResponse(new Response('Request could not be fully inspected.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE));

			return;
		} catch (\Throwable $exception) {
			$this->logger->error('Firewall request evaluation failed closed.', [
				'site_id' => $siteId,
				'method' => $request->getMethod(),
				'path' => $request->getPathInfo(),
				'exception' => $exception,
			]);
			$this->dispatchSecurityEvent(
				[new FirewallFinding('core:evaluation-failed', 'Firewall evaluation failed.', FirewallAction::Deny)],
				FirewallAction::Deny,
				$this->requestSummary($request, $siteId),
				$siteId,
			);
			$event->setResponse(new Response('Request security inspection is unavailable.', Response::HTTP_SERVICE_UNAVAILABLE));

			return;
		}

		$this->dispatchSecurityEvent($result->findings, $result->getAction(), $context->getSafeSummary(), $siteId);

		if (FirewallAction::Deny === $result->getAction()) {
			$event->setResponse(new Response('Request blocked by security policy.', Response::HTTP_FORBIDDEN));
		}
	}

	/**
	 * @param list<FirewallFinding>                                                   $findings
	 * @param array{method:string,path:string,siteId:int|string|null,clientIp:string} $summary
	 */
	private function dispatchSecurityEvent(array $findings, FirewallAction $action, array $summary, int|string|null $siteId): void
	{
		try {
			$this->eventDispatcher->dispatch(
				new FirewallSecurityEvent($findings, $action, $summary),
				FirewallSecurityEvent::NAME,
			);
		} catch (\Throwable $exception) {
			$this->logger->warning('A listener for iikiti.security failed.', [
				'site_id' => $siteId,
				'exception' => $exception,
			]);
		}
	}

	/** @return array{method:string,path:string,siteId:int|string|null,clientIp:string} */
	private function requestSummary(\Symfony\Component\HttpFoundation\Request $request, int|string|null $siteId): array
	{
		return [
			'method' => $request->getMethod(),
			'path' => $request->getPathInfo(),
			'siteId' => $siteId,
			'clientIp' => (string) $request->getClientIp(),
		];
	}

	private function isApiRequest(string $path): bool
	{
		return '/api' === $path || str_starts_with($path, '/api/');
	}
}
