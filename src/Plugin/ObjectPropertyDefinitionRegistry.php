<?php

declare(strict_types=1);

namespace iikiti\CMS\Plugin;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Collects plugin-defined object properties and their query capabilities. */
final class ObjectPropertyDefinitionRegistry
{
	/** @var array<string,ObjectPropertyDefinition> */
	private array $definitions = [];

	/** @param iterable<ObjectPropertyDefinitionProviderInterface> $providers */
	public function __construct(
		#[AutowireIterator('iikiti.cms.object_property_definition')]
		iterable $providers,
	) {
		foreach ($providers as $provider) {
			foreach ($provider->getObjectPropertyDefinitions() as $definition) {
				if (!$definition instanceof ObjectPropertyDefinition) {
					throw new \LogicException(sprintf('%s returned a value that is not an ObjectPropertyDefinition.', $provider::class));
				}

				$key = $definition->key();
				if (isset($this->definitions[$key])) {
					throw new \LogicException(sprintf('Object property definition "%s" is registered more than once.', $key));
				}
				$this->definitions[$key] = $definition;
			}
		}
	}

	public function get(string $objectType, string $propertyName): ?ObjectPropertyDefinition
	{
		$type = strtolower($objectType);
		$definition = $this->definitions[$type.'::'.$propertyName] ?? null;
		if (null !== $definition) {
			return $definition;
		}

		$shortNameMatches = [];
		foreach ($this->definitions as $candidate) {
			$parts = explode('\\', $candidate->objectType);
			if (strtolower((string) end($parts)) === $type && $candidate->propertyName === $propertyName) {
				$shortNameMatches[] = $candidate;
			}
		}
		if (1 === count($shortNameMatches)) {
			return $shortNameMatches[0];
		}
		if (count($shortNameMatches) > 1) {
			throw new \LogicException(sprintf('Object property type "%s" is ambiguous; declare its discriminator value.', $objectType));
		}

		return $this->definitions['*::'.$propertyName] ?? null;
	}

	/** @return list<ObjectPropertyDefinition> */
	public function all(): array
	{
		return array_values($this->definitions);
	}
}
