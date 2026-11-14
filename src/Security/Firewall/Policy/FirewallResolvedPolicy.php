<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Policy;

/**
 * Effective protection policy for one request scope: rate limits plus DoS and bot settings.
 *
 * `rateLimits` is the list consumed by {@see \iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimiterManager};
 * `dos` and `bots` are the merged setting maps consumed by the DoS guard and bot detector.
 * `ipMatch` is an optional authoritative IP decision supplied by the API runtime for consumer
 * deployments; when present, Stage 1 honors it instead of evaluating local IP rules.
 *
 * @phpstan-type ResolvedPolicyShape array{rateLimits:list<array<string,mixed>>,dos:array<string,mixed>,bots:array<string,mixed>}
 */
final readonly class FirewallResolvedPolicy
{
	/**
	 * @param list<array<string,mixed>>        $rateLimits
	 * @param array<string,mixed>              $dos
	 * @param array<string,mixed>              $bots
	 * @param array{action:string}|null        $ipMatch
	 */
	public function __construct(
		public array $rateLimits,
		public array $dos,
		public array $bots,
		public ?array $ipMatch = null,
	) {
	}

	/**
	 * @param ResolvedPolicyShape $policy
	 */
	public static function fromArray(array $policy): self
	{
		return new self($policy['rateLimits'], $policy['dos'], $policy['bots']);
	}

	/** @return ResolvedPolicyShape */
	public function toArray(): array
	{
		return ['rateLimits' => $this->rateLimits, 'dos' => $this->dos, 'bots' => $this->bots];
	}
}
