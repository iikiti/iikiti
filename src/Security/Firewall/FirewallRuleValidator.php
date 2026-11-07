<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

final readonly class FirewallRuleValidator
{
	private const MAX_RULES = 1000;
	private const MAX_DEPTH = 8;
	private const MAX_CHILDREN = 64;
	private const MAX_NODES = 256;

	public function __construct(private FirewallConditionRegistry $conditions)
	{
	}

	/**
	 * @param array<mixed> $rules
	 *
	 * @return list<array{id:string,name:string,enabled:bool,action:string,priority:int,conditions:array<string,mixed>}>
	 */
	public function validateRules(array $rules, ?FirewallRequestContext $context = null): array
	{
		$context?->assertWithinBudget();
		if (!array_is_list($rules) || count($rules) > self::MAX_RULES) {
			throw new \InvalidArgumentException('Firewall rules must be a list of at most 1000 items.');
		}

		$validated = [];
		$ids = [];
		foreach ($rules as $rule) {
			$context?->assertWithinBudget();
			if (!is_array($rule)) {
				throw new \InvalidArgumentException('Every firewall rule must be an object.');
			}
			if ([] !== array_diff(array_keys($rule), ['id', 'name', 'enabled', 'action', 'priority', 'conditions'])) {
				throw new \InvalidArgumentException('Firewall rule contains unsupported fields.');
			}
			$id = $rule['id'] ?? null;
			$name = $rule['name'] ?? null;
			$action = $rule['action'] ?? FirewallAction::Report->value;
			$enabled = $rule['enabled'] ?? true;
			$priority = $rule['priority'] ?? 0;
			$tree = $rule['conditions'] ?? null;
			if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $id) || isset($ids[$id]) ||
				!is_string($name) || '' === trim($name) || strlen($name) > 256 ||
				!is_string($action) || null === FirewallAction::tryFrom($action) ||
				!is_bool($enabled) || !is_int($priority) || abs($priority) > 100000 || !is_array($tree)
			) {
				throw new \InvalidArgumentException('Firewall rule metadata or condition tree is invalid.');
			}
			$ids[$id] = true;
			$nodeCount = 0;
			$this->validateNode($tree, 0, $nodeCount, $context);
			$validated[] = [
				'id' => $id,
				'name' => trim($name),
				'enabled' => $enabled,
				'action' => $action,
				'priority' => $priority,
				'conditions' => $tree,
			];
		}

		usort($validated, static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);

		$context?->assertWithinBudget();

		return $validated;
	}

	/** @param array<string,mixed> $node */
	private function validateNode(array $node, int $depth, int &$nodeCount, ?FirewallRequestContext $context): void
	{
		$context?->assertWithinBudget();
		if (++$nodeCount > self::MAX_NODES) {
			throw new \InvalidArgumentException('A firewall condition tree cannot exceed 256 nodes.');
		}
		if ($depth > self::MAX_DEPTH) {
			throw new \InvalidArgumentException('Firewall condition trees cannot exceed eight nested groups.');
		}
		$groupKeys = array_intersect(['all', 'any'], array_keys($node));
		if ([] !== $groupKeys) {
			if (1 !== count($groupKeys) || 1 !== count($node) || !is_array($children = $node[array_values($groupKeys)[0]]) ||
				!array_is_list($children) || [] === $children || count($children) > self::MAX_CHILDREN
			) {
				throw new \InvalidArgumentException('Firewall groups require one non-empty all/any list of at most 64 conditions.');
			}
			foreach ($children as $child) {
				if (!is_array($child)) {
					throw new \InvalidArgumentException('Firewall condition nodes must be objects.');
				}
				$this->validateNode($child, $depth + 1, $nodeCount, $context);
			}

			return;
		}

		if (2 !== count($node) || !is_string($name = $node['condition'] ?? null) || !is_array($options = $node['options'] ?? null)) {
			throw new \InvalidArgumentException('Firewall condition leaves require only condition and options fields.');
		}
		$this->conditions->get($name)->validateOptions($options);
	}
}
