<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Definition;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class WorkflowDefinitionProviderRegistry
{
	/** @var list<WorkflowDefinitionProviderInterface> */
	private array $providers;

	/** @param iterable<WorkflowDefinitionProviderInterface> $providers */
	public function __construct(
		#[AutowireIterator('workflow.definition_provider')]
		iterable $providers = [],
	) {
		$this->providers = is_array($providers) ? array_values($providers) : iterator_to_array($providers, false);
	}

	/** @return list<WorkflowDefinitionProviderEntry> */
	public function getDefinitions(): array
	{
		$entries = [];
		$seen = [];
		foreach ($this->providers as $provider) {
			$ownerKey = $provider->getOwnerKey();
			$sourceVersion = $provider->getSourceVersion();
			if (!preg_match('~^[a-z0-9][a-z0-9_.\/-]{0,127}$~D', $ownerKey)) {
				throw new \LogicException(sprintf('Workflow definition provider has invalid owner key "%s".', $ownerKey));
			}
			if ('' === trim($sourceVersion) || mb_strlen($sourceVersion) > 64) {
				throw new \LogicException(sprintf('Workflow definition provider "%s" has invalid source version.', $ownerKey));
			}
			foreach ($provider->getDefinitions() as $definition) {
				$identity = $ownerKey.'|'.$definition->key;
				if (isset($seen[$identity])) {
					throw new \LogicException(sprintf('Workflow definition "%s" is provided more than once by plugin "%s".', $definition->key, $ownerKey));
				}
				$seen[$identity] = true;
				$entries[] = new WorkflowDefinitionProviderEntry($ownerKey, $sourceVersion, $definition);
			}
		}

		return $entries;
	}
}
