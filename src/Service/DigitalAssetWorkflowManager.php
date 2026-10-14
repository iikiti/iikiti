<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use iikiti\CMS\Entity\AssetWorkflowRun;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Event\DigitalAssetEvent;
use iikiti\CMS\Event\DigitalAssetEvents;
use iikiti\CMS\Repository\AssetWorkflowDefinitionRepository;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\StateMachine\AssetProcessingStateMachine;
use iikiti\CMS\StateMachine\AssetRunStateMachine;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DigitalAssetWorkflowManager
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly DigitalAssetRepository $assets,
		private readonly AssetWorkflowDefinitionRepository $workflows,
		private readonly DigitalAssetProcessorRegistry $processors,
		private readonly EventDispatcherInterface $eventDispatcher,
		private readonly AssetProcessingStateMachine $assetProcessingStateMachine,
		private readonly AssetRunStateMachine $assetRunStateMachine,
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

		foreach ($this->workflows->findBy(['enabled' => true], ['id' => 'ASC']) as $workflow) {
			if (!$workflow->matches($asset->getMimeType())) {
				continue;
			}
			$this->processWorkflow($asset, $workflow);
		}

		$this->assetProcessingStateMachine->complete($asset);
		$this->entityManager->flush();
		$this->eventDispatcher->dispatch(new DigitalAssetEvent($asset), DigitalAssetEvents::PROCESSING_COMPLETED);
	}

	private function processWorkflow(AbstractDigitalAsset $asset, AssetWorkflowDefinition $workflow): void
	{
		$repository = $this->entityManager->getRepository(AssetWorkflowRun::class);
		$run = $repository->findOneBy(['asset' => $asset, 'workflow' => $workflow]);
		if ($run instanceof AssetWorkflowRun && 'complete' === $run->getStatus()) {
			return;
		}
		if (!$run instanceof AssetWorkflowRun) {
			$run = new AssetWorkflowRun($asset, $workflow);
			$this->entityManager->persist($run);
		}

		$this->assetRunStateMachine->start($run);
		$this->entityManager->flush();

		try {
			if (!$this->matchesConditions($asset, $workflow->getConditions())) {
				$this->assetRunStateMachine->skip($run);
				$this->entityManager->flush();

				return;
			}
			$this->processors->get($workflow->getProcessorKey())->process($asset, $workflow);
			$this->assetRunStateMachine->complete($run);
			$this->entityManager->flush();
		} catch (\Throwable $exception) {
			$this->assetRunStateMachine->fail($run, $exception->getMessage());
			$this->assetProcessingStateMachine->fail($asset);
			$this->entityManager->flush();
			$this->eventDispatcher->dispatch(new DigitalAssetEvent($asset), DigitalAssetEvents::PROCESSING_FAILED);
			throw $exception;
		}
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
