<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use iikiti\CMS\Security\Firewall\Dos\FirewallAnomalyDetector;
use iikiti\CMS\Security\Firewall\Dos\FirewallAutoBanManager;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimitContext;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimitDecision;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimiterManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Stage-1 follow-ups that need more context than the request event: post-authentication rate
 * limits, rate-limit response headers, the 4xx error-storm signal, and concurrency slot release.
 */
final readonly class FirewallFeedbackSubscriber implements EventSubscriberInterface
{
	private const POST_AUTH_PRIORITY = 7;

	public function __construct(
		private Security $security,
		private FirewallRateLimiterManager $rateLimiter,
		private FirewallAnomalyDetector $anomalies,
		private FirewallAutoBanManager $autoBans,
		private LoggerInterface $logger,
	) {
	}

	public static function getSubscribedEvents(): array
	{
		return [
			// After the request subscriber (9) and before Symfony Security (8) completes its firewall.
			KernelEvents::REQUEST => ['onPostAuthRequest', self::POST_AUTH_PRIORITY],
			KernelEvents::RESPONSE => ['onKernelResponse', 0],
			KernelEvents::TERMINATE => ['onKernelTerminate', 0],
		];
	}

	public function onPostAuthRequest(RequestEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$request = $event->getRequest();
		$context = $request->attributes->get(FirewallRequestSubscriber::CONTEXT_ATTRIBUTE);
		if (!is_array($context) || !is_array($context['policy'] ?? null) || !is_array($context['policy']['rateLimits'] ?? null)) {
			return;
		}

		if (!$this->hasPostAuthRule($context['policy']['rateLimits'])) {
			return;
		}

		try {
			$user = $this->security->getUser();
		} catch (\LogicException) {
			// Stateful lazy firewalls without a SiteRegistry site cannot hydrate User::getRoles().
			return;
		}
		if (null === $user) {
			return;
		}

		$rateContext = new FirewallRateLimitContext(
			clientIp: (string) $context['clientIp'],
			method: $request->getMethod(),
			path: $request->getPathInfo(),
			routeGroup: (string) ($context['routeGroup'] ?? 'public'),
			authenticated: true,
			userIdentifier: $user->getUserIdentifier(),
			siteId: is_int($context['siteId'] ?? null) || is_string($context['siteId'] ?? null) ? $context['siteId'] : null,
		);
		$decisions = $this->rateLimiter->evaluate($context['policy']['rateLimits'], 'post_auth', $rateContext);
		$rejected = $this->firstThrottled($decisions);
		if (null === $rejected) {
			return;
		}

		$response = new JsonResponse(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS);
		foreach ($rejected->toHeaders() as $name => $value) {
			$response->headers->set($name, $value);
		}
		$event->setResponse($response);
	}

	public function onKernelResponse(ResponseEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$request = $event->getRequest();
		$response = $event->getResponse();

		$decision = $request->attributes->get(FirewallRequestSubscriber::RATE_LIMIT_ATTRIBUTE);
		if ($decision instanceof FirewallRateLimitDecision) {
			foreach ($decision->toHeaders() as $name => $value) {
				if (!$response->headers->has($name)) {
					$response->headers->set($name, $value);
				}
			}
		}

		$context = $request->attributes->get(FirewallRequestSubscriber::CONTEXT_ATTRIBUTE);
		if (!is_array($context) || !is_array($context['policy'] ?? null) || !is_array($context['policy']['dos'] ?? null)) {
			return;
		}
		$dos = $context['policy']['dos'];
		$clientIp = (string) $context['clientIp'];
		$siteId = is_int($context['siteId'] ?? null) || is_string($context['siteId'] ?? null) ? $context['siteId'] : null;

		$stormed = $this->anomalies->recordResponseStatus($clientIp, $response->getStatusCode(), $dos, $siteId);
		if (!$stormed) {
			return;
		}
		$this->logger->info('Firewall 4xx error storm detected.', ['status' => $response->getStatusCode(), 'site_id' => $siteId]);
		if ('enforce' === ($dos['errorStormAction'] ?? 'report')) {
			$this->autoBans->recordStrike($clientIp, 'core:error_storm', 'Client produced a burst of client errors.', $dos, $siteId);
		}
	}

	public function onKernelTerminate(TerminateEvent $event): void
	{
		$slotKey = $event->getRequest()->attributes->get(FirewallRequestSubscriber::CONCURRENCY_SLOT_ATTRIBUTE);
		if (!is_string($slotKey) || '' === $slotKey) {
			return;
		}
		$this->anomalies->releaseConcurrencySlot($slotKey);
	}

	/** @param list<array<string,mixed>> $rules */
	private function hasPostAuthRule(array $rules): bool
	{
		foreach ($rules as $rule) {
			if (true === ($rule['enabled'] ?? true) && in_array($rule['keyBy'] ?? '', ['user', 'token'], true)) {
				return true;
			}
		}

		return false;
	}

	/** @param list<FirewallRateLimitDecision> $decisions */
	private function firstThrottled(array $decisions): ?FirewallRateLimitDecision
	{
		foreach ($decisions as $decision) {
			if (!$decision->accepted && 'throttle' === $decision->action) {
				return $decision;
			}
		}

		return null;
	}
}
