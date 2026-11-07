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

	/** @param list<array<string,mixed>>|FirewallCompiledRuleSet $rules */
	public function evaluate(array|FirewallCompiledRuleSet $rules, FirewallRequestContext $context): FirewallEvaluationResult
	{
		if (is_array($rules)) {
			$rules = new FirewallCompiledRuleSet($this->validator->validateRules($rules, $context), $this->conditions);
		}
		$findings = [];
		foreach ($rules->getCandidateRules($context, $this->conditions) as $rule) {
			$context->assertWithinBudget();
			$matchedConditions = $rule['enabled'] ? $this->matchConditions($rule['conditions'], $context) : null;
			if (null === $matchedConditions) {
				continue;
			}
			$findings[] = new FirewallFinding(
				$rule['id'],
				$rule['name'],
				FirewallAction::from($rule['action']),
				$rule['priority'],
				$matchedConditions,
			);
		}

		return new FirewallEvaluationResult($findings);
	}

	/**
	 * @param array<string,mixed> $node
	 *
	 * @return list<array{condition:string,target?:string,name?:string,operator?:string,transformations?:list<string>}>
	 */
	private function matchConditions(array $node, FirewallRequestContext $context): ?array
	{
		$context->assertWithinBudget();
		if (isset($node['all'])) {
			$matched = [];
			foreach ($node['all'] as $child) {
				$childMatches = $this->matchConditions($child, $context);
				if (null === $childMatches) {
					return null;
				}
				array_push($matched, ...$childMatches);
			}

			return $matched;
		}
		if (isset($node['any'])) {
			foreach ($node['any'] as $child) {
				$childMatches = $this->matchConditions($child, $context);
				if (null !== $childMatches) {
					return $childMatches;
				}
			}

			return null;
		}

		$conditionName = (string) $node['condition'];
		if (!$this->conditions->get($conditionName)->matches($node['options'], $context)) {
			return null;
		}

		$details = ['condition' => substr($conditionName, 0, 128)];
		foreach (['target', 'name', 'operator'] as $key) {
			if (is_string($node['options'][$key] ?? null)) {
				$details[$key] = substr($node['options'][$key], 0, 128);
			}
		}
		if (is_array($node['options']['transformations'] ?? null)) {
			$details['transformations'] = array_values(array_map(
				static fn (string $transformation): string => substr($transformation, 0, 64),
				array_filter(
					array_slice($node['options']['transformations'], 0, 8),
					static fn (mixed $value): bool => is_string($value),
				),
			));
		}

		return [$details];
	}
}
