<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Fragment;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Prebuilt ID lookup for all core and plugin fragment providers. */
final class FragmentProviderRegistry
{
	/** @var array<string,FragmentProviderInterface> */
	private array $providers = [];

	/** @param iterable<FragmentProviderInterface> $providers */
	public function __construct(#[AutowireIterator('iikiti.cms.fragment_provider')] iterable $providers = [])
	{
		foreach ($providers as $provider) {
			$name = $provider->getName();
			if (1 !== preg_match('/^[a-z0-9][a-z0-9-]{0,62}\.[a-z][a-z0-9_.-]{0,63}$/D', $name)) {
				throw new \LogicException(sprintf('Fragment provider ID "%s" must be plugin-namespaced.', $name));
			}
			if (isset($this->providers[$name])) {
				throw new \LogicException(sprintf('Fragment provider ID "%s" is registered more than once.', $name));
			}
			$this->providers[$name] = $provider;
		}
	}

	public function has(string $name): bool
	{
		return isset($this->providers[$name]);
	}

	public function get(string $name): ?FragmentProviderInterface
	{
		return $this->providers[$name] ?? null;
	}
}
