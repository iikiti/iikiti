<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

final readonly class FirewallRuleEvaluator
{
	public function __construct(
		private FirewallRuleValidator $validator,
		private FirewallConditionRegistry $conditions,
	) {
	}

	/** @param list<array<string,mixed>> $rules */
	public function evaluate(array $rules, FirewallRequestContext $context): FirewallEvaluationResult
	{
		$findings = [];
		foreach ($this->validator->validateRules($rules, $context) as $rule) {
			$context->assertWithinBudget();
			if (!$rule['enabled'] || !$this->matches($rule['conditions'], $context)) {
				continue;
			}
			$findings[] = new FirewallFinding($rule['id'], $rule['name'], FirewallAction::from($rule['action']));
		}

		return new FirewallEvaluationResult($findings);
	}

	/** @param array<string,mixed> $node */
	private function matches(array $node, FirewallRequestContext $context): bool
	{
		$context->assertWithinBudget();
		if (isset($node['all'])) {
			foreach ($node['all'] as $child) {
				if (!$this->matches($child, $context)) {
					return false;
				}
			}

			return true;
		}
		if (isset($node['any'])) {
			foreach ($node['any'] as $child) {
				if ($this->matches($child, $context)) {
					return true;
				}
			}

			return false;
		}

		return $this->conditions->get((string) $node['condition'])->matches($node['options'], $context);
	}
}
