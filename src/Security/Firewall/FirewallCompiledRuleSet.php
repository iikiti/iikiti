<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

final readonly class FirewallCompiledRuleSet
{
	/** @var array<string,list<int>> */
	private array $rulesByIndexKey;

	/** @var list<int> */
	private array $unindexedRules;

	/**
	 * @param list<array{id:string,name:string,enabled:bool,action:string,priority:int,conditions:array<string,mixed>}> $rules
	 */
	public function __construct(
		private array $rules,
		FirewallConditionRegistry $conditions,
	) {
		$rulesByIndexKey = [];
		$unindexedRules = [];
		foreach ($rules as $ruleIndex => $rule) {
			if (!$rule['enabled']) {
				continue;
			}
			$ruleKeys = [];
			$hasUnindexedCondition = false;
			$this->collectIndexKeys($rule['conditions'], $conditions, $ruleKeys, $hasUnindexedCondition);
			if ($hasUnindexedCondition || [] === $ruleKeys) {
				$unindexedRules[] = $ruleIndex;
				continue;
			}
			foreach (array_keys($ruleKeys) as $key) {
				$rulesByIndexKey[$key][] = $ruleIndex;
			}
		}
		$this->rulesByIndexKey = $rulesByIndexKey;
		$this->unindexedRules = $unindexedRules;
	}

	/**
	 * @return list<array{id:string,name:string,enabled:bool,action:string,priority:int,conditions:array<string,mixed>}>
	 */
	public function getCandidateRules(FirewallRequestContext $context, FirewallConditionRegistry $conditions): array
	{
		$indices = array_fill_keys($this->unindexedRules, true);
		foreach ($this->rulesByIndexKey as $key => $ruleIndices) {
			if (!$context->hasIndexKey($key)) {
				continue;
			}
			foreach ($ruleIndices as $ruleIndex) {
				$indices[$ruleIndex] = true;
			}
		}
		if ([] === $indices) {
			return [];
		}
		ksort($indices, SORT_NUMERIC);

		$candidates = [];
		foreach (array_keys($indices) as $ruleIndex) {
			$context->assertWithinBudget();
			$rule = $this->rules[$ruleIndex];
			if ($this->couldMatch($rule['conditions'], $context, $conditions)) {
				$candidates[] = $rule;
			}
		}

		return $candidates;
	}

	/** @return list<array{id:string,name:string,enabled:bool,action:string,priority:int,conditions:array<string,mixed>}> */
	public function getRules(): array
	{
		return $this->rules;
	}

	/**
	 * @param array<string,mixed> $node
	 * @param array<string,true>  $keys
	 */
	private function collectIndexKeys(
		array $node,
		FirewallConditionRegistry $conditions,
		array &$keys,
		bool &$hasUnindexedCondition,
	): void {
		if (isset($node['all']) || isset($node['any'])) {
			foreach ($node['all'] ?? $node['any'] as $child) {
				$this->collectIndexKeys($child, $conditions, $keys, $hasUnindexedCondition);
			}

			return;
		}

		$condition = $conditions->get($node['condition']);
		if (!$condition instanceof FirewallConditionIndexInterface) {
			$hasUnindexedCondition = true;

			return;
		}
		$indexKeys = $condition->getRequestIndexKeys($node['options']);
		if ([] === $indexKeys) {
			$hasUnindexedCondition = true;

			return;
		}
		foreach ($indexKeys as $key) {
			$keys[$key] = true;
		}
	}

	/** @param array<string,mixed> $node */
	private function couldMatch(array $node, FirewallRequestContext $context, FirewallConditionRegistry $conditions): bool
	{
		if (isset($node['all'])) {
			foreach ($node['all'] as $child) {
				if (!$this->couldMatch($child, $context, $conditions)) {
					return false;
				}
			}

			return true;
		}
		if (isset($node['any'])) {
			foreach ($node['any'] as $child) {
				if ($this->couldMatch($child, $context, $conditions)) {
					return true;
				}
			}

			return false;
		}

		$condition = $conditions->get($node['condition']);
		if (!$condition instanceof FirewallConditionIndexInterface) {
			return true;
		}

		return $condition->couldMatchRequest($node['options'], $context);
	}
}
