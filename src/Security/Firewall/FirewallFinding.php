<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

final readonly class FirewallFinding
{
	public function __construct(
		public string $ruleId,
		public string $message,
		public FirewallAction $action,
	) {
	}

	/** @return array{ruleId:string,message:string,action:string} */
	public function toArray(): array
	{
		return [
			'ruleId' => $this->ruleId,
			'message' => $this->message,
			'action' => $this->action->value,
		];
	}
}
