<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class FirewallConditionRegistry
{
	/** @var array<string,FirewallConditionInterface> */
	private array $conditions = [];

	/** @param iterable<FirewallConditionInterface> $conditions */
	public function __construct(#[AutowireIterator('iikiti.security.firewall_condition')] iterable $conditions = [])
	{
		foreach ($conditions as $condition) {
			$name = $condition->getName();
			if (!preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $name) || isset($this->conditions[$name])) {
				throw new \LogicException(sprintf('Invalid or duplicate firewall condition name "%s".', $name));
			}
			$this->conditions[$name] = $condition;
		}
	}

	public function get(string $name): FirewallConditionInterface
	{
		return $this->conditions[$name] ?? throw new \InvalidArgumentException(sprintf('Unknown firewall condition "%s".', $name));
	}

	public function has(string $name): bool
	{
		return isset($this->conditions[$name]);
	}

	/** @return list<string> */
	public function getNames(): array
	{
		return array_keys($this->conditions);
	}
}
