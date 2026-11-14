<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Ip;

use iikiti\CMS\Security\Firewall\FirewallFinding;

/**
 * Outcome of evaluating a client IP against compiled IP rules.
 */
final readonly class FirewallIpMatchResult
{
	/** @param list<FirewallFinding> $findings */
	public function __construct(
		public string $action,
		public ?FirewallIpRuleSpec $matchedRule,
		public array $findings,
	) {
	}
}
