<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

final readonly class FirewallFinding
{
	/**
	 * @param list<array{condition:string,target?:string,name?:string,operator?:string,transformations?:list<string>}> $matchedConditions
	 */
	public function __construct(
		public string $ruleId,
		public string $message,
		public FirewallAction $action,
		public int $priority = 0,
		public array $matchedConditions = [],
	) {
	}

	/** @return array{ruleId:string,message:string,action:string,priority:int,matchedConditions:list<array<string,mixed>>} */
	public function toArray(): array
	{
		return [
			'ruleId' => $this->ruleId,
			'message' => $this->message,
			'action' => $this->action->value,
			'priority' => $this->priority,
			'matchedConditions' => $this->matchedConditions,
		];
	}
}
