<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

final readonly class FirewallEvaluationResult
{
	/** @param list<FirewallFinding> $findings */
	public function __construct(public array $findings)
	{
	}

	public function getAction(): FirewallAction
	{
		foreach ($this->findings as $finding) {
			if (FirewallAction::Deny === $finding->action) {
				return FirewallAction::Deny;
			}
		}

		return FirewallAction::Report;
	}
}
