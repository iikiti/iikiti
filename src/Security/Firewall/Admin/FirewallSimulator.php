<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Admin;

use iikiti\CMS\Security\Firewall\Bot\FirewallBotDetector;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotEvaluationResult;
use iikiti\CMS\Security\Firewall\Dos\FirewallDosGuard;
use iikiti\CMS\Security\Firewall\FirewallAction;
use iikiti\CMS\Security\Firewall\FirewallFinding;
use iikiti\CMS\Security\Firewall\FirewallRequestContextFactory;
use iikiti\CMS\Security\Firewall\FirewallRuleEvaluator;
use iikiti\CMS\Security\Firewall\FirewallRuleSetResolver;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleStore;
use iikiti\CMS\Security\Firewall\Policy\FirewallResolvedPolicy;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimitContext;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimitDecision;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimiterManager;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRouteClassifier;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Security\Firewall\Policy\FirewallProtectionPolicyResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dry-run evaluation of hypothetical requests against every firewall stage.
 *
 * Guarantees: nothing is mutated. Rate limits use the non-consuming {@see FirewallRateLimiterManager::simulate()}
 * peek, bot verification and DoS inspection are pure, and no strikes, bans, audit rows, or
 * security events are produced. The request is synthetic and body-less, so no stream is read.
 */
final readonly class FirewallSimulator
{
	public function __construct(
		private FirewallIpRuleStore $ipRules,
		private FirewallBotDetector $bots,
		private FirewallDosGuard $dosGuard,
		private FirewallRateLimiterManager $rateLimiter,
		private FirewallRouteClassifier $routes,
		private FirewallProtectionPolicyResolverInterface $policies,
		private FirewallRuleSetResolver $ruleSets,
		private FirewallRuleEvaluator $evaluator,
		private FirewallRequestContextFactory $contexts,
	) {
	}

	/**
	 * @param array<string,string> $headers
	 *
	 * @return array{decision:string,ipMatch:array<string,mixed>,botMatch:array<string,mixed>,dosResult:array<string,mixed>,rateLimits:list<array<string,mixed>>,wafFindings:list<array<string,mixed>>}
	 */
	public function simulate(
		?Site $site,
		string $clientIp,
		string $method,
		string $path,
		array $headers,
		bool $authenticated,
	): array {
		$siteId = $site?->getId();
		$request = $this->syntheticRequest($clientIp, $method, $path, $headers);
		$policy = $this->policies->resolveFor($site, true);
		$routeGroup = $this->routes->classify($path);

		$ip = $this->ipRules->getMatcher($siteId)->match($clientIp);
		$bot = $this->botDecision($request, $clientIp, $routeGroup, $policy, $siteId);
		$dos = $this->dosGuard->inspect($request, $policy->dos, $routeGroup);
		$rateLimits = $this->rateLimiter->simulate($policy->rateLimits, 'pre_auth', new FirewallRateLimitContext(
			clientIp: $clientIp,
			method: $method,
			path: $path,
			routeGroup: $routeGroup,
			authenticated: $authenticated,
			botIdentity: $bot->botId,
			siteId: $siteId,
		));
		$waf = $this->wafFindings($request, $siteId, $site);

		return [
			'decision' => $this->decide($ip->action, $bot, $dos->blocked, $rateLimits, $waf),
			'ipMatch' => ['action' => $ip->action, 'matchedRuleId' => $ip->matchedRule?->id],
			'botMatch' => $bot->toArray(),
			'dosResult' => ['blocked' => $dos->blocked, 'statusCode' => $dos->statusCode, 'findings' => $dos->findings],
			'rateLimits' => array_map(static fn (FirewallRateLimitDecision $decision): array => [
				'ruleId' => $decision->ruleId,
				'accepted' => $decision->accepted,
				'action' => $decision->action,
				'remaining' => $decision->remaining,
				'limit' => $decision->limit,
				'retryAfter' => $decision->retryAfter,
			], $rateLimits),
			'wafFindings' => array_map(static fn (FirewallFinding $finding): array => $finding->toArray(), $waf),
		];
	}

	private function botDecision(Request $request, string $clientIp, string $routeGroup, FirewallResolvedPolicy $policy, int|string|null $siteId): FirewallBotEvaluationResult
	{
		return $this->bots->evaluate($request, $clientIp, $routeGroup, $policy->bots, $siteId);
	}

	/** @return list<FirewallFinding> */
	private function wafFindings(Request $request, int|string|null $siteId, ?Site $site): array
	{
		$rules = null === $site
			? $this->ruleSets->getGlobalRules()
			: $this->ruleSets->getRulesForSite($site, true);

		return $this->evaluator->evaluate($rules, $this->contexts->create($request, $siteId))->findings;
	}

	/**
	 * Resolves the overall decision in precedence order: IP block > bot block > DoS > rate limit >
	 * bot challenge > WAF deny > report-only signals > allow.
	 *
	 * @param list<FirewallRateLimitDecision> $rateLimits
	 * @param list<FirewallFinding>           $waf
	 */
	private function decide(string $ipAction, FirewallBotEvaluationResult $bot, bool $dosBlocked, array $rateLimits, array $waf): string
	{
		if ('block' === $ipAction) {
			return 'block';
		}
		if ('block' === $bot->action) {
			return 'block';
		}
		// The structural guard only rejects with 400/413, so any DoS hit is a hard block.
		if ($dosBlocked) {
			return 'block';
		}
		foreach ($rateLimits as $decision) {
			if (!$decision->accepted && 'throttle' === $decision->action) {
				return 'throttle';
			}
		}
		if ('challenge' === $bot->action) {
			return 'challenge';
		}
		foreach ($waf as $finding) {
			if (FirewallAction::Deny === $finding->action) {
				return 'block';
			}
		}
		$hasRateLimitBreach = array_any($rateLimits, static fn (FirewallRateLimitDecision $decision): bool => !$decision->accepted);
		if ('report' === $ipAction || 'throttle' === $bot->action || 'report' === $bot->action || [] !== $waf || $hasRateLimitBreach) {
			return 'report';
		}

		return 'allow';
	}

	/** @param array<string,string> $headers */
	private function syntheticRequest(string $clientIp, string $method, string $path, array $headers): Request
	{
		$server = ['REMOTE_ADDR' => $clientIp];
		foreach ($headers as $name => $value) {
			$server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
		}

		return Request::create($path, strtoupper($method), [], [], [], $server);
	}
}
