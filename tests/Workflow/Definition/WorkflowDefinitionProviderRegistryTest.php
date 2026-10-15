<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Workflow\Definition;

use iikiti\CMS\Workflow\Definition\WorkflowDefinitionData;
use iikiti\CMS\Workflow\Definition\WorkflowDefinitionProviderInterface;
use iikiti\CMS\Workflow\Definition\WorkflowDefinitionProviderRegistry;
use PHPUnit\Framework\TestCase;

final class WorkflowDefinitionProviderRegistryTest extends TestCase
{
	public function testCollectsPluginDefinitionsWithTheirOwners(): void
	{
		$provider = $this->createProvider('vendor/plugin', [new WorkflowDefinitionData(
			'approval',
			'Approval',
			['places' => ['start'], 'transitions' => [], 'initialPlaces' => ['start'], 'finalPlaces' => ['start']],
			'plugin.approval_policy',
		)]);

		$entries = (new WorkflowDefinitionProviderRegistry([$provider]))->getDefinitions();

		self::assertCount(1, $entries);
		self::assertSame('vendor/plugin', $entries[0]->ownerKey);
		self::assertSame('approval', $entries[0]->definition->key);
	}

	public function testRejectsDuplicateKeysWithinPluginOwner(): void
	{
		$definition = new WorkflowDefinitionData(
			'approval',
			'Approval',
			['places' => ['start'], 'transitions' => [], 'initialPlaces' => ['start'], 'finalPlaces' => ['start']],
			'plugin.approval_policy',
		);
		$registry = new WorkflowDefinitionProviderRegistry([
			$this->createProvider('vendor/plugin', [$definition]),
			$this->createProvider('vendor/plugin', [$definition]),
		]);

		$this->expectException(\LogicException::class);
		$registry->getDefinitions();
	}

	/** @param list<WorkflowDefinitionData> $definitions */
	private function createProvider(string $ownerKey, array $definitions): WorkflowDefinitionProviderInterface
	{
		return new class($ownerKey, $definitions) implements WorkflowDefinitionProviderInterface {
			/** @param list<WorkflowDefinitionData> $definitions */
			public function __construct(private readonly string $ownerKey, private readonly array $definitions)
			{
			}

			public function getOwnerKey(): string
			{
				return $this->ownerKey;
			}

			public function getSourceVersion(): string
			{
				return '1.0.0';
			}

			public function getDefinitions(): iterable
			{
				return $this->definitions;
			}
		};
	}
}
