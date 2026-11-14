<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotDetector;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotEvaluationResult;
use iikiti\CMS\Security\Firewall\Challenge\FirewallChallengeManager;
use iikiti\CMS\Security\Firewall\Dos\FirewallAnomalyDetector;
use iikiti\CMS\Security\Firewall\Dos\FirewallAutoBanManager;
use iikiti\CMS\Security\Firewall\Dos\FirewallDosGuard;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleStore;
use iikiti\CMS\Security\Firewall\Policy\FirewallProtectionPolicyResolverInterface;
use iikiti\CMS\Security\Firewall\Policy\FirewallResolvedPolicy;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimitContext;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimitDecision;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimiterManager;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRouteClassifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Runs the two-stage firewall pipeline on the main request.
 *
 * Stage 1 is metadata-only gating that runs before any body is read: IP rules, bot
 * classification, structural DoS limits, pre-auth rate limits and expensive-route concurrency.
 * Stage 2 is the body-aware WAF rule evaluation, skipped when a verified trusted automation
 * is allowed to bypass it. Stage-1 collaborators are optional so the original five-argument
 * construction keeps working for Stage-2-only deployments and tests.
 */
final readonly class FirewallRequestSubscriber implements EventSubscriberInterface
{
	public const CONTEXT_ATTRIBUTE = '_iikiti_firewall_context';
	public const RATE_LIMIT_ATTRIBUTE = '_iikiti_firewall_rate_limit';
	public const CONCURRENCY_SLOT_ATTRIBUTE = '_iikiti_firewall_concurrency_slot';

	private const CHALLENGE_VERIFY_ROUTE = 'firewall_challenge_verify';
	private const INTERNAL_RESOLVER_ROUTES = ['api_internal_firewall_resolve', 'api_internal_response_headers_resolve'];
	private const RETRY_AFTER_SECONDS = 5;

	// Keep the policy/status control plane recoverable; inherited global rules still run.
	private const SITE_POLICY_OPERATIONS = [
		'site_firewall_policy_get',
		'site_firewall_policy_put',
		'admin_firewall_pack_status',
		'admin_firewall_pack_activate',
		'site_firewall_protection_get',
		'site_firewall_protection_put',
		'site_firewall_ip_rules_get',
		'site_firewall_ip_rules_post',
		'site_firewall_ip_rule_get',
		'site_firewall_ip_rule_put',
		'site_firewall_ip_rule_delete',
		'site_firewall_bans_get',
		'site_firewall_ban_delete',
		'site_firewall_automations_get',
		'site_firewall_automations_post',
		'site_firewall_automation_put',
		'site_firewall_automation_delete',
	];

	public function __construct(
		private FirewallRequestContextFactory $contexts,
		private FirewallRuleSetResolver $rules,
		private FirewallRuleEvaluator $evaluator,
		private EventDispatcherInterface $eventDispatcher,
		private LoggerInterface $logger,
		private ?FirewallStageOneServices $stageOne = null,
		#[Autowire('%application_role%')]
		private string $applicationRole = 'api',
		#[Autowire('%firewall_enabled%')]
		private bool $enabled = true,
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
		if (!$this->enabled) {
			return;
		}

		$request = $event->getRequest();
		$path = $request->getPathInfo();
		$site = SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null;
		// Consumer runtimes must still gate public routes at Stage 1 (IP/bot/rate limits) even without a
		// matched site; Stage 2 WAF stays limited to API traffic or site-scoped requests there.
		$isConsumer = 'consumer' === $this->applicationRole;
		if (null === $site && !$this->isApiRequest($path) && !$isConsumer) {
			return;
		}
		if (in_array($request->attributes->get('_route'), self::INTERNAL_RESOLVER_ROUTES, true)) {
			// Signed internal resolvers are called only by consumer runtimes; WAF loops skip them.
			return;
		}

		$siteId = $site?->getId();
		$operation = $request->attributes->get('_api_operation_name');
		$includeSiteRules = !is_string($operation) || !in_array($operation, self::SITE_POLICY_OPERATIONS, true);

		$bypassWaf = false;
		if (null !== $this->stageOne) {
			$stage = $this->runStageOne($event, $request, $site, $siteId, $includeSiteRules);
			if (null === $stage) {
				return;
			}
			$bypassWaf = $stage;
		}

		// Stage 2 needs a site or an API route; a consumer public route without a site has no WAF scope.
		$skipStageTwo = null === $site && !$this->isApiRequest($path);
		if (!$bypassWaf && !$skipStageTwo) {
			$this->runStageTwo($event, $request, $site, $siteId, $includeSiteRules);
		}
	}

	/**
	 * Runs Stage 1. Returns null when the event has been short-circuited with a response,
	 * otherwise whether Stage 2 should be bypassed.
	 */
	private function runStageOne(RequestEvent $event, Request $request, ?Site $site, int|string|null $siteId, bool $includeSiteRules): ?bool
	{
		$services = $this->stageOne;
		if (null === $services) {
			return false;
		}

		$clientIp = (string) $request->getClientIp();
		$routeGroup = $services->routes->classify($request->getPathInfo());
		$policy = $services->policyResolver->resolveFor($site, $includeSiteRules, $request->getHost(), $clientIp);
		$dos = $policy->dos;
		$metadata = ['ip_action' => 'none', 'bot' => null, 'rate_limit' => null, 'dos_status' => 'allowed', 'bypass_waf' => false];

		// The API runtime's authoritative ipMatch (consumer mode) takes precedence over local rules.
		$ipAction = $policy->ipMatch['action'] ?? null;
		if (null === $ipAction) {
			$ipAction = $services->ipRules->getMatcher($siteId)->match($clientIp)->action;
		}
		$metadata['ip_action'] = $ipAction;
		if ('block' === $ipAction) {
			$this->denyStageOne($event, $request, $siteId, $clientIp, [new FirewallFinding('core:ip-block', 'Client IP matched a block rule.', FirewallAction::Deny)], $metadata, 'ip_block', Response::HTTP_FORBIDDEN, 'Request blocked by security policy.');

			return null;
		}

		$bot = FirewallBotEvaluationResult::none();
		if (self::CHALLENGE_VERIFY_ROUTE !== $request->attributes->get('_route')) {
			$bot = $services->bots->evaluate($request, $clientIp, $routeGroup, $policy->bots, $siteId);
			$metadata['bot'] = $this->botSummary($bot);
			if ('block' === $bot->action) {
				$this->recordStrike($services, $clientIp, 'bot_block', $dos, $siteId);
				$this->denyStageOne($event, $request, $siteId, $clientIp, $bot->findings, $metadata, 'bot_block', Response::HTTP_FORBIDDEN, 'Request blocked by security policy.');

				return null;
			}
			if ('challenge' === $bot->action && !$services->challenges->hasValidClearance($request, $policy->bots)) {
				$this->dispatchSecurityEvent($request, $siteId, $clientIp, $bot->findings, FirewallAction::Deny, $metadata);
				$event->setResponse($services->challenges->challenge($request, $policy->bots, 'bot_challenge') ?? $this->denyResponse($request, 'Request blocked by security policy.', Response::HTTP_FORBIDDEN));

				return null;
			}
		}

		$bypassWaf = $bot->bypassWaf && 'allow' === $bot->action;
		$metadata['bypass_waf'] = $bypassWaf;

		$structural = $services->dosGuard->inspect($request, $dos, $routeGroup);
		if ($structural->blocked) {
			$metadata['dos_status'] = 'blocked';
			$findings = array_map(
				static fn (string $code): FirewallFinding => new FirewallFinding($code, 'Structural request limit exceeded.', FirewallAction::Deny),
				$structural->findings,
			);
			$this->recordStrike($services, $clientIp, 'dos', $dos, $siteId);
			$message = Response::HTTP_REQUEST_ENTITY_TOO_LARGE === $structural->statusCode ? 'Request entity too large.' : 'Bad request.';
			$this->denyStageOne($event, $request, $siteId, $clientIp, $findings, $metadata, 'dos_block', $structural->statusCode, $message);

			return null;
		}

		$rlContext = new FirewallRateLimitContext(
			clientIp: $clientIp,
			method: $request->getMethod(),
			path: $request->getPathInfo(),
			routeGroup: $routeGroup,
			authenticated: false,
			botIdentity: $bot->botId,
			siteId: $siteId,
		);
		$decisions = $services->rateLimiter->evaluate($this->rateLimitsFor($policy, $bot), 'pre_auth', $rlContext);
		$last = [] === $decisions ? null : $decisions[array_key_last($decisions)];
		$request->attributes->set(self::RATE_LIMIT_ATTRIBUTE, $last);
		$metadata['rate_limit'] = null === $last ? null : ['rule' => $last->ruleId, 'accepted' => $last->accepted];

		$rejected = $this->firstThrottled($decisions);
		if (null !== $rejected) {
			$this->recordStrike($services, $clientIp, 'rate_limit', $dos, $siteId);
			$this->denyStageOne(
				$event, $request, $siteId, $clientIp,
				[new FirewallFinding($rejected->ruleId, 'Rate limit exceeded.', FirewallAction::Deny)],
				$metadata, 'rate_limit', Response::HTTP_TOO_MANY_REQUESTS, 'Too many requests.', $rejected->toHeaders(),
			);

			return null;
		}

		$slot = $services->anomalies->acquireConcurrencySlot($clientIp, $routeGroup, $dos, $siteId);
		if ($slot->exceeded && 'enforce' === ($dos['concurrencyAction'] ?? 'report')) {
			$this->denyStageOne(
				$event, $request, $siteId, $clientIp,
				[new FirewallFinding('core:concurrency', 'Expensive route concurrency limit reached.', FirewallAction::Deny)],
				$metadata, 'concurrency', Response::HTTP_TOO_MANY_REQUESTS, 'Too many requests.',
				['Retry-After' => (string) self::RETRY_AFTER_SECONDS],
			);

			return null;
		}
		if (null !== $slot->slotKey) {
			$request->attributes->set(self::CONCURRENCY_SLOT_ATTRIBUTE, $slot->slotKey);
		}

		$request->attributes->set(self::CONTEXT_ATTRIBUTE, [
			'policy' => $policy->toArray(),
			'clientIp' => $clientIp,
			'siteId' => $siteId,
			'routeGroup' => $routeGroup,
			'stage' => $metadata,
		]);

		return $bypassWaf;
	}

	private function runStageTwo(RequestEvent $event, Request $request, ?Site $site, int|string|null $siteId, bool $includeSiteRules): void
	{
		try {
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
				$request, $siteId, null,
				[new FirewallFinding('core:inspection-limit', 'Request could not be fully inspected.', FirewallAction::Deny)],
				FirewallAction::Deny, [],
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
				$request, $siteId, null,
				[new FirewallFinding('core:evaluation-failed', 'Firewall evaluation failed.', FirewallAction::Deny)],
				FirewallAction::Deny, [],
			);
			$event->setResponse(new Response('Request security inspection is unavailable.', Response::HTTP_SERVICE_UNAVAILABLE));

			return;
		}

		$stage = $this->stageMetadataFromRequest($request);
		$this->dispatchSecurityEvent(
			$request, $siteId, null,
			$result->findings, $result->getAction(), $stage, $context->getSafeSummary(),
		);

		if (FirewallAction::Deny === $result->getAction()) {
			$this->recordWafStrike($request, $siteId);
			$event->setResponse($this->denyResponse($request, 'Request blocked by security policy.', Response::HTTP_FORBIDDEN));
		}
	}

	/**
	 * Shared Stage-1 short-circuit: dispatches the event and sets a response without reading the body.
	 *
	 * @param list<FirewallFinding> $findings
	 * @param array<string,mixed>   $metadata
	 * @param array<string,string>  $headers
	 */
	private function denyStageOne(
		RequestEvent $event,
		Request $request,
		int|string|null $siteId,
		string $clientIp,
		array $findings,
		array $metadata,
		string $reason,
		int $status,
		string $message,
		array $headers = [],
	): void {
		$metadata['denied_by'] = $reason;
		$this->dispatchSecurityEvent($request, $siteId, $clientIp, $findings, FirewallAction::Deny, $metadata);

		$response = $this->denyResponse($request, $message, $status);
		foreach ($headers as $name => $value) {
			$response->headers->set($name, $value);
		}
		$event->setResponse($response);
	}

	private function denyResponse(Request $request, string $message, int $status): Response
	{
		if ($this->isApiRequest($request->getPathInfo())) {
			return new JsonResponse(['error' => $message], $status);
		}

		return new Response($message, $status);
	}

	/**
	 * Dispatches a FirewallSecurityEvent. Stage-2 callers pass a null client IP and use the request's own address.
	 *
	 * @param list<FirewallFinding>                                                        $findings
	 * @param array<string,mixed>                                                          $metadata
	 * @param array{method:string,path:string,siteId:int|string|null,clientIp:string}|null $summary
	 */
	private function dispatchSecurityEvent(
		Request $request,
		int|string|null $siteId,
		?string $clientIp,
		array $findings,
		FirewallAction $action,
		array $metadata,
		?array $summary = null,
	): void {
		$requestSummary = $summary ?? [
			'method' => $request->getMethod(),
			'path' => $request->getPathInfo(),
			'siteId' => $siteId,
			'clientIp' => $clientIp ?? (string) $request->getClientIp(),
		];

		try {
			$this->eventDispatcher->dispatch(
				new FirewallSecurityEvent($findings, $action, $requestSummary, $metadata),
				FirewallSecurityEvent::NAME,
			);
		} catch (\Throwable $exception) {
			$this->logger->warning('A listener for iikiti.security failed.', [
				'site_id' => $siteId,
				'exception' => $exception,
			]);
		}
	}

	/**
	 * @param list<FirewallRateLimitDecision> $decisions
	 */
	private function firstThrottled(array $decisions): ?FirewallRateLimitDecision
	{
		foreach ($decisions as $decision) {
			if (!$decision->accepted && 'throttle' === $decision->action) {
				return $decision;
			}
		}

		return null;
	}

	/**
	 * Uses a verified trusted automation's rate budget when one applies; otherwise the resolved rules.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rateLimitsFor(FirewallResolvedPolicy $policy, FirewallBotEvaluationResult $bot): array
	{
		if (null === $bot->rateLimitOverride) {
			return $policy->rateLimits;
		}

		return [[
			'id' => 'automation:'.($bot->trustedAutomationId ?? 'override'),
			'name' => 'Trusted automation budget',
			'enabled' => true,
			'priority' => 0,
			'inheritance' => 'required',
			'action' => 'throttle',
			'algorithm' => 'sliding_window',
			'limit' => $bot->rateLimitOverride['limit'],
			'interval' => $bot->rateLimitOverride['interval'],
			'keyBy' => 'bot_identity',
			'match' => ['methods' => [], 'pathPrefix' => '', 'pathRegex' => null, 'routeGroup' => 'custom', 'authenticated' => 'any'],
		]];
	}

	/** @return array{action:string,category:?string,botId:?string,verified:bool,impersonated:bool,bypassWaf:bool} */
	private function botSummary(FirewallBotEvaluationResult $bot): array
	{
		return [
			'action' => $bot->action,
			'category' => $bot->category,
			'botId' => $bot->botId,
			'verified' => $bot->verified,
			'impersonated' => $bot->impersonated,
			'bypassWaf' => $bot->bypassWaf,
		];
	}

	/** @param array<string,mixed> $dos */
	private function recordStrike(FirewallStageOneServices $services, string $clientIp, string $reasonCode, array $dos, int|string|null $siteId): void
	{
		$services->autoBans->recordStrike($clientIp, 'core:'.$reasonCode, sprintf('Strike recorded for %s.', $reasonCode), $dos, $siteId);
	}

	private function recordWafStrike(Request $request, int|string|null $siteId): void
	{
		$services = $this->stageOne;
		if (null === $services) {
			return;
		}
		$context = $request->attributes->get(self::CONTEXT_ATTRIBUTE);
		$dos = is_array($context) && is_array($context['policy'] ?? null) && is_array($context['policy']['dos'] ?? null)
			? $context['policy']['dos']
			: [];
		$this->recordStrike($services, (string) $request->getClientIp(), 'waf', $dos, $siteId);
	}

	/** @return array<string,mixed> */
	private function stageMetadataFromRequest(Request $request): array
	{
		$context = $request->attributes->get(self::CONTEXT_ATTRIBUTE);

		return is_array($context) && is_array($context['stage'] ?? null) ? $context['stage'] : [];
	}

	private function isApiRequest(string $path): bool
	{
		return '/api' === $path || str_starts_with($path, '/api/');
	}
}
