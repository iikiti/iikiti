<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Event\DigitalAssetEvent;
use iikiti\CMS\Event\DigitalAssetEvents;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\Workflow\WorkflowDefinitionRepository;
use iikiti\CMS\StateMachine\AssetProcessingStateMachine;
use iikiti\CMS\Workflow\Runtime\WorkflowInstanceManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DigitalAssetWorkflowManager
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly DigitalAssetRepository $assets,
		private readonly WorkflowDefinitionRepository $workflows,
		private readonly DigitalAssetProcessorRegistry $processors,
		private readonly WorkflowInstanceManagerInterface $instances,
		private readonly EventDispatcherInterface $eventDispatcher,
		private readonly AssetProcessingStateMachine $assetProcessingStateMachine,
	) {
	}

	public function process(int|string $assetId): void
	{
		$asset = $this->assets->find($assetId);
		if (!$asset instanceof UploadedDigitalAsset) {
			return;
		}

		$this->assetProcessingStateMachine->process($asset);
		$this->entityManager->flush();
		$this->eventDispatcher->dispatch(new DigitalAssetEvent($asset), DigitalAssetEvents::PROCESSING_STARTED);

		try {
			foreach ($this->workflowItems($asset) as $item) {
				if (!$this->processWorkflow($asset, $item['version'], $item['processor'], $item['site'], $item['instance'])) {
					return;
				}
			}

			$this->assetProcessingStateMachine->complete($asset);
			$this->entityManager->flush();
			$this->eventDispatcher->dispatch(new DigitalAssetEvent($asset), DigitalAssetEvents::PROCESSING_COMPLETED);
		} catch (\Throwable $exception) {
			$asset = $this->managedAsset($asset);
			$this->assetProcessingStateMachine->fail($asset);
			$this->entityManager->flush();
			$this->eventDispatcher->dispatch(new DigitalAssetEvent($asset), DigitalAssetEvents::PROCESSING_FAILED);

			throw $exception;
		}
	}

	/**
	 * Workflow transactions clear the shared EntityManager on rollback; reload
	 * the asset so the aggregate failure status persists.
	 */
	private function managedAsset(UploadedDigitalAsset $asset): UploadedDigitalAsset
	{
		$id = $asset->getId();
		if (null === $id) {
			return $asset;
		}
		$managed = $this->assets->find($id);

		return $managed instanceof UploadedDigitalAsset ? $managed : $asset;
	}

	/** @return list<array{version:WorkflowDefinitionVersion,processor:AssetWorkflowDefinition,site:?Site,instance:?WorkflowInstance}> */
	private function workflowItems(UploadedDigitalAsset $asset): array
	{
		$itemsByKey = [];
		$definitions = $this->workflows->findEnabled();
		foreach ($definitions as $definition) {
			$version = $this->currentVersion($definition);
			if (null === $version || null === ($processor = $this->processorDefinition($version)) || !$processor->matches($asset->getMimeType())) {
				continue;
			}
			$site = $this->siteForDefinition($definition, $asset, true === ($version->getGraph()['metadata']['assetProcessor']['allSites'] ?? false));
			if (false === $site) {
				continue;
			}
			$idempotencyKey = $this->idempotencyKey($asset, $definition);
			$instance = $this->instances->findByIdempotencyKey($idempotencyKey);
			if ($instance instanceof WorkflowInstance && $this->isComplete($instance)) {
				continue;
			}
			if ($instance instanceof WorkflowInstance) {
				$version = $instance->getDefinitionVersion();
				$processor = $this->processorDefinition($version) ?? $processor;
				$site = $instance->getSite();
			}
			$itemsByKey[$idempotencyKey] = ['version' => $version, 'processor' => $processor, 'site' => $site, 'instance' => $instance];
		}

		$id = $asset->getId();
		if (null !== $id) {
			foreach ($this->instances->findBySubject('digital_asset', $id) as $instance) {
				if ($this->isComplete($instance) || 'terminated' === $instance->getExecutionState()) {
					continue;
				}
				if ($instance->isPaused()) {
					$definition = $instance->getDefinitionVersion()->getDefinition();
					$idempotencyKey = $this->idempotencyKey($asset, $definition);
					$processor = $this->processorDefinition($instance->getDefinitionVersion());
					if (null !== $processor) {
						$itemsByKey[$idempotencyKey] = [
							'version' => $instance->getDefinitionVersion(),
							'processor' => $processor,
							'site' => $instance->getSite(),
							'instance' => $instance,
						];
					}
					continue;
				}
				$definition = $instance->getDefinitionVersion()->getDefinition();
				$idempotencyKey = $this->idempotencyKey($asset, $definition);
				if (isset($itemsByKey[$idempotencyKey])) {
					continue;
				}
				$processor = $this->processorDefinition($instance->getDefinitionVersion());
				if (null !== $processor) {
					$itemsByKey[$idempotencyKey] = [
						'version' => $instance->getDefinitionVersion(),
						'processor' => $processor,
						'site' => $instance->getSite(),
						'instance' => $instance,
					];
				}
			}
		}
		$items = array_values($itemsByKey);
		usort($items, static fn (array $left, array $right): int => (int) $left['version']->getDefinition()->getId() <=> (int) $right['version']->getDefinition()->getId());

		return $items;
	}

	private function processWorkflow(
		UploadedDigitalAsset $asset,
		WorkflowDefinitionVersion $version,
		AssetWorkflowDefinition $processor,
		?Site $site,
		?WorkflowInstance $instance,
	): bool {
		if (null === $instance) {
			$definitionId = $version->getDefinition()->getId();
			$assetId = $asset->getId();
			if (null === $definitionId || null === $assetId) {
				throw new \LogicException('Asset workflow definitions and assets must be persisted before processing.');
			}
			$instance = $this->instances->start(
				$version,
				$site,
				'digital_asset',
				$assetId,
				[],
				null,
				$this->idempotencyKey($asset, $version->getDefinition()),
			);
		}
		if ($this->isComplete($instance)) {
			return true;
		}
		if (true === $instance->isPaused()) {
			return false;
		}

		$startTransition = $this->enabledAssetTransition($instance, 'start');
		if (null === $startTransition) {
			return false;
		}
		if (null === $this->instances->applyTransition($instance, $startTransition, null)) {
			return false;
		}

		$conditions = $processor->getConditions();
		if (!$this->matchesConditions($asset, $conditions)) {
			$this->instances->applyTransition($instance, $this->enabledAssetTransition($instance, 'skip') ?? throw new \LogicException('Asset skip transition is unavailable.'), null);

			return true;
		}

		try {
			$registeredProcessor = $this->processors->get($processor->getProcessorKey());
			if (!$registeredProcessor->supportsMimePattern($processor->getMimePattern())) {
				throw new \InvalidArgumentException(sprintf('Processor "%s" does not support MIME pattern "%s".', $processor->getProcessorKey(), $processor->getMimePattern()));
			}
			$registeredProcessor->process($asset, $processor);
			$this->instances->applyTransition($instance, $this->enabledAssetTransition($instance, 'complete') ?? throw new \LogicException('Asset complete transition is unavailable.'), null);

			return true;
		} catch (\Throwable $exception) {
			$instance = $this->managedInstance($instance);
			$failTransition = $this->enabledAssetTransition($instance, 'fail');
			if (null !== $failTransition) {
				$this->instances->applyTransition($instance, $failTransition, null, errorMessage: $exception->getMessage());
			}

			throw $exception;
		}
	}

	/**
	 * Workflow transactions clear the shared EntityManager on rollback, which
	 * detaches the instance; reload it so bookkeeping writes stay managed.
	 */
	private function managedInstance(WorkflowInstance $instance): WorkflowInstance
	{
		$id = $instance->getId();
		if (null === $id) {
			return $instance;
		}

		return $this->instances->find((string) $id) ?? $instance;
	}

	private function currentVersion(WorkflowDefinition $definition): ?WorkflowDefinitionVersion
	{
		$version = $this->entityManager->getRepository(WorkflowDefinitionVersion::class)->findOneBy([
			'definition' => $definition,
			'version' => $definition->getCurrentVersion(),
		]);

		return $version instanceof WorkflowDefinitionVersion ? $version : null;
	}

	private function processorDefinition(WorkflowDefinitionVersion $version): ?AssetWorkflowDefinition
	{
		$metadata = $version->getGraph()['metadata']['assetProcessor'] ?? null;
		if (!is_array($metadata) ||
			!is_string($metadata['processorKey'] ?? null) ||
			!is_string($metadata['mimePattern'] ?? null) ||
			!is_array($metadata['conditions'] ?? []) ||
			!is_array($metadata['options'] ?? [])) {
			return null;
		}
		$definition = $version->getDefinition();

		return new AssetWorkflowDefinition(
			$definition->getKey(),
			$definition->getName(),
			$metadata['processorKey'],
			$metadata['mimePattern'],
			$metadata['conditions'] ?? [],
			$metadata['options'] ?? [],
		);
	}

	private function siteForDefinition(WorkflowDefinition $definition, UploadedDigitalAsset $asset, bool $allSites): Site|false|null
	{
		if ($allSites && 'core' === $definition->getOwnerType()) {
			return null;
		}
		foreach ($asset->getAccessSites() as $site) {
			$assigned = $definition->hasSite($site);
			if ('site' === $definition->getOwnerType() && ($definition->getOwnerKey() !== (string) $site->getId() || !$assigned)) {
				continue;
			}
			if ('plugin' === $definition->getOwnerType() && (!$assigned || !$site->getConfiguration()->isPluginActive($definition->getOwnerKey()))) {
				continue;
			}
			if ('core' === $definition->getOwnerType() && !$allSites && !$assigned) {
				continue;
			}
			if (in_array($definition->getOwnerType(), ['core', 'site', 'plugin'], true)) {
				return $site;
			}
		}

		return false;
	}

	private function idempotencyKey(UploadedDigitalAsset $asset, WorkflowDefinition $definition): string
	{
		if (null === $asset->getId() || null === $definition->getId()) {
			throw new \LogicException('Persisted asset workflow instances require database IDs.');
		}

		return sprintf('asset:%s:%s', (string) $asset->getId(), (string) $definition->getId());
	}

	private function isComplete(WorkflowInstance $instance): bool
	{
		return 'complete' === $this->status($instance);
	}

	private function status(WorkflowInstance $instance): ?string
	{
		$marking = $instance->getMarking();

		return 1 === count($marking) ? array_key_first($marking) : null;
	}

	private function enabledAssetTransition(WorkflowInstance $instance, string $operation): ?string
	{
		foreach ($this->instances->getEnabledTransitionMetadata($instance, null) as $transition) {
			if (($transition['metadata']['assetOperation'] ?? null) === $operation) {
				return $transition['name'];
			}
		}

		return null;
	}

	/** @param array<string,mixed> $conditions */
	private function matchesConditions(AbstractDigitalAsset $asset, array $conditions): bool
	{
		$size = (int) ($asset->getSize() ?? 0);
		if (isset($conditions['min_size']) && $size < (int) $conditions['min_size']) {
			return false;
		}
		if (isset($conditions['max_size']) && $size > (int) $conditions['max_size']) {
			return false;
		}

		return true;
	}
}
