<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\RateLimit;

use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Evaluates configured rate-limit rules against a request using symfony/rate-limiter.
 *
 * Pre-auth rules are keyed by network identity (IP, subnet, route, bot, site). Post-auth rules
 * need the resolved user or API token and run once authentication has completed.
 */
final class FirewallRateLimiterManager
{
	private const PRE_AUTH_KEYS = ['ip', 'subnet', 'ip_and_route', 'bot_identity', 'site'];
	private const POST_AUTH_KEYS = ['user', 'token'];
	private const MIN_INTERVAL = 1;

	private readonly CacheStorage $storage;

	public function __construct(
		#[Autowire(service: 'cache.firewall_ratelimit')]
		CacheItemPoolInterface $cache,
	) {
		$this->storage = new CacheStorage($cache);
	}

	/**
	 * Evaluates every enabled rule matching the phase and returns decisions in evaluation order.
	 *
	 * Evaluation stops at the first rejected throttle decision; report-only rejections are recorded
	 * and evaluation continues so every breach is visible.
	 *
	 * @param list<array<string,mixed>> $rules
	 *
	 * @return list<FirewallRateLimitDecision>
	 */
	public function evaluate(array $rules, string $phase, FirewallRateLimitContext $context, bool $dryRun = false): array
	{
		$decisions = [];
		foreach ($rules as $rule) {
			if (!$this->appliesToPhase($rule, $phase) || !$this->matches($rule, $context)) {
				continue;
			}
			$decision = $this->consumeRule($rule, $this->keyFor($rule, $context), $dryRun);
			$decisions[] = $decision;
			if (!$decision->accepted && 'throttle' === $decision->action) {
				break;
			}
		}

		return $decisions;
	}

	/**
	 * Dry-run evaluation for the simulator: reports decisions without spending any tokens.
	 *
	 * @param list<array<string,mixed>> $rules
	 *
	 * @return list<FirewallRateLimitDecision>
	 */
	public function simulate(array $rules, string $phase, FirewallRateLimitContext $context): array
	{
		return $this->evaluate($rules, $phase, $context, true);
	}

	/** @param array<string,mixed> $rule */
	private function appliesToPhase(array $rule, string $phase): bool
	{
		$keyBy = (string) $rule['keyBy'];
		if ('pre_auth' === $phase) {
			return in_array($keyBy, self::PRE_AUTH_KEYS, true);
		}

		return 'post_auth' === $phase && in_array($keyBy, self::POST_AUTH_KEYS, true);
	}

	/** @param array<string,mixed> $rule */
	private function matches(array $rule, FirewallRateLimitContext $context): bool
	{
		/** @var array<string,mixed> $match */
		$match = $rule['match'];
		if ([] !== $match['methods'] && !in_array($context->method, $match['methods'], true)) {
			return false;
		}
		if (!$this->routeMatches($match, $context)) {
			return false;
		}

		return match ($match['authenticated']) {
			'anonymous' => !$context->authenticated,
			'authenticated' => $context->authenticated,
			default => true,
		};
	}

	/** @param array<string,mixed> $match */
	private function routeMatches(array $match, FirewallRateLimitContext $context): bool
	{
		if ('custom' !== $match['routeGroup'] && $match['routeGroup'] !== $context->routeGroup) {
			return false;
		}
		if ('' !== $match['pathPrefix'] && !str_starts_with($context->path, (string) $match['pathPrefix'])) {
			return false;
		}
		if (null !== $match['pathRegex'] && 1 !== preg_match((string) $match['pathRegex'], $context->path)) {
			return false;
		}

		return true;
	}

	/** @param array<string,mixed> $rule */
	private function consumeRule(array $rule, string $key, bool $dryRun): FirewallRateLimitDecision
	{
		$limiter = $this->factoryFor($rule)->create($key);
		// consume(0) reads the current window without spending a token, giving a side-effect-free peek.
		$rateLimit = $limiter->consume($dryRun ? 0 : 1);

		return $this->toDecision($rule, $rateLimit);
	}

	/** @param array<string,mixed> $rule */
	private function factoryFor(array $rule): RateLimiterFactory
	{
		$id = 'fw_rl:'.(string) $rule['id'];
		$interval = (int) $rule['interval'];
		if ($interval < self::MIN_INTERVAL) {
			throw new \InvalidArgumentException('Rate limit interval must be at least one second.');
		}
		$config = match ($rule['algorithm']) {
			// Token bucket: `burst` is the bucket capacity; `limit` tokens refill every `interval` seconds.
			'token_bucket' => [
				'id' => $id,
				'policy' => 'token_bucket',
				'limit' => (int) ($rule['burst'] ?? $rule['limit']),
				'rate' => ['interval' => $interval.' seconds', 'amount' => (int) $rule['limit']],
			],
			'fixed_window' => ['id' => $id, 'policy' => 'fixed_window', 'limit' => (int) $rule['limit'], 'interval' => $interval.' seconds'],
			default => ['id' => $id, 'policy' => 'sliding_window', 'limit' => (int) $rule['limit'], 'interval' => $interval.' seconds'],
		};

		return new RateLimiterFactory($config, $this->storage);
	}

	/** @param array<string,mixed> $rule */
	private function keyFor(array $rule, FirewallRateLimitContext $context): string
	{
		$parts = match ((string) $rule['keyBy']) {
			'ip' => [$context->clientIp],
			'subnet' => [IpCidrNormalizer::subnetKey($context->clientIp) ?? $context->clientIp],
			'ip_and_route' => [$context->clientIp, $context->routeGroup],
			'bot_identity' => [$context->botIdentity ?? 'none'],
			'site' => [(string) ($context->siteId ?? '0')],
			'user' => [$context->userIdentifier ?? 'anonymous'],
			'token' => [$context->tokenIdentifier ?? 'none'],
			default => [$context->clientIp],
		};

		// Hash the key so attacker-controlled values never reach the cache key namespace verbatim.
		return hash('sha256', implode("\0", [(string) $rule['id'], ...$parts]));
	}

	/** @param array<string,mixed> $rule */
	private function toDecision(array $rule, RateLimit $rateLimit): FirewallRateLimitDecision
	{
		$accepted = $rateLimit->isAccepted();
		$resetAt = $rateLimit->getResetAt() ?? new \DateTimeImmutable();
		$retryAfter = $accepted ? 0 : max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());

		return new FirewallRateLimitDecision(
			accepted: $accepted,
			ruleId: (string) $rule['id'],
			ruleName: (string) $rule['name'],
			action: (string) $rule['action'],
			limit: $rateLimit->getLimit(),
			remaining: $rateLimit->getRemainingTokens(),
			resetAt: $resetAt,
			retryAfter: $retryAfter,
		);
	}
}
