<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Bot;

use iikiti\CMS\Security\Firewall\FirewallFinding;

/**
 * Outcome of bot/automation classification for one request.
 *
 * @phpstan-type RateLimitOverride array{limit:int,interval:int}
 */
final readonly class FirewallBotEvaluationResult
{
	public const ACTIONS = ['allow', 'throttle', 'report', 'challenge', 'block', 'none'];

	/**
	 * @param array{limit:int,interval:int}|null $rateLimitOverride
	 * @param list<FirewallFinding>              $findings
	 */
	public function __construct(
		public string $action = 'none',
		public ?string $category = null,
		public ?string $botId = null,
		public ?string $botName = null,
		public bool $verified = false,
		public bool $impersonated = false,
		public bool $bypassWaf = false,
		public ?string $trustedAutomationId = null,
		public ?array $rateLimitOverride = null,
		public array $findings = [],
	) {
	}

	public static function none(): self
	{
		return new self();
	}

	public function withAction(string $action): self
	{
		return new self(
			$action,
			$this->category,
			$this->botId,
			$this->botName,
			$this->verified,
			$this->impersonated,
			$this->bypassWaf,
			$this->trustedAutomationId,
			$this->rateLimitOverride,
			$this->findings,
		);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'action' => $this->action,
			'category' => $this->category,
			'botId' => $this->botId,
			'botName' => $this->botName,
			'verified' => $this->verified,
			'impersonated' => $this->impersonated,
			'bypassWaf' => $this->bypassWaf,
			'trustedAutomationId' => $this->trustedAutomationId,
			'rateLimitOverride' => $this->rateLimitOverride,
			'findings' => array_map(static fn (FirewallFinding $finding): array => $finding->toArray(), $this->findings),
		];
	}
}
