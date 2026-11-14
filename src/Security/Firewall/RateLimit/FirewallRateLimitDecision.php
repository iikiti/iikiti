<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\RateLimit;

/**
 * Outcome of evaluating one rate-limit rule for a request.
 */
final readonly class FirewallRateLimitDecision
{
	public function __construct(
		public bool $accepted,
		public string $ruleId,
		public string $ruleName,
		public string $action,
		public int $limit,
		public int $remaining,
		public \DateTimeImmutable $resetAt,
		public int $retryAfter = 0,
	) {
	}

	/**
	 * Standard rate-limit response headers. Retry-After is only emitted for rejected requests.
	 *
	 * @return array<string,string>
	 */
	public function toHeaders(): array
	{
		$headers = [
			'X-RateLimit-Limit' => (string) $this->limit,
			'X-RateLimit-Remaining' => (string) max(0, $this->remaining),
			'X-RateLimit-Reset' => (string) $this->resetAt->getTimestamp(),
		];
		if (!$this->accepted) {
			$headers['Retry-After'] = (string) max(1, $this->retryAfter);
		}

		return $headers;
	}
}
