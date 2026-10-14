<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use iikiti\CMS\Entity\AssetWorkflowRun;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Event\DigitalAssetEvents;
use iikiti\CMS\Repository\AssetWorkflowDefinitionRepository;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Service\DigitalAssetProcessorInterface;
use iikiti\CMS\Service\DigitalAssetProcessorRegistry;
use iikiti\CMS\Service\DigitalAssetWorkflowManager;
use iikiti\CMS\StateMachine\AssetProcessingStateMachine;
use iikiti\CMS\StateMachine\AssetRunStateMachine;
use iikiti\CMS\Tests\Support\StateMachineFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DigitalAssetWorkflowManagerTest extends TestCase
{
	private UploadedDigitalAsset $asset;
	private AssetWorkflowDefinition $workflow;

	protected function setUp(): void
	{
		$this->asset = new UploadedDigitalAsset('Notes', 'text/plain', str_repeat('c', 64));
		$this->workflow = new AssetWorkflowDefinition('unit-flow', 'Unit', 'noop', 'text/*');
	}

	public function testSuccessfulProcessingCompletesAssetAndRunAndEmitsLifecycleEvents(): void
	{
		$events = [];
		$run = new AssetWorkflowRun($this->asset, $this->workflow);
		$manager = $this->createManager(new NoopProcessor(), $events, $run);

		$manager->process(1);

		self::assertSame('complete', $this->asset->getProcessingStatus());
		self::assertSame('complete', $run->getStatus());
		self::assertSame(1, $run->getAttempts());
		self::assertNull($run->getLastError());
		self::assertSame([
			DigitalAssetEvents::PROCESSING_STARTED,
			DigitalAssetEvents::PROCESSING_COMPLETED,
		], $events);
	}

	public function testUnmetConditionsSkipTheRunButCompleteTheAsset(): void
	{
		$events = [];
		$this->workflow->setConditions(['min_size' => 1000]);
		$run = new AssetWorkflowRun($this->asset, $this->workflow);
		$manager = $this->createManager(new NoopProcessor(), $events, $run);

		$manager->process(1);

		self::assertSame('skipped', $run->getStatus());
		self::assertSame('complete', $this->asset->getProcessingStatus());
		self::assertSame([
			DigitalAssetEvents::PROCESSING_STARTED,
			DigitalAssetEvents::PROCESSING_COMPLETED,
		], $events);
	}

	public function testProcessorFailureMarksRunAndAssetAsFailedEmitsEventAndRethrows(): void
	{
		$events = [];
		$run = new AssetWorkflowRun($this->asset, $this->workflow);
		$this->workflow->setProcessorKey('throwing');
		$manager = $this->createManager(new ThrowingProcessor('boom'), $events, $run);

		try {
			$manager->process(1);
			self::fail('Processor failures must propagate after marking.');
		} catch (\RuntimeException) {
		}

		self::assertSame('failed', $run->getStatus());
		self::assertSame('boom', $run->getLastError());
		self::assertSame('failed', $this->asset->getProcessingStatus());
		self::assertSame([
			DigitalAssetEvents::PROCESSING_STARTED,
			DigitalAssetEvents::PROCESSING_FAILED,
		], $events);
	}

	public function testCompletedRunIsNotRunTwice(): void
	{
		$events = [];
		$run = new AssetWorkflowRun($this->asset, $this->workflow);
		$run->setStatus('complete');
		$manager = $this->createManager(new NoopProcessor(), $events, $run);

		$manager->process(1);

		self::assertSame(0, $run->getAttempts());
		self::assertSame([
			DigitalAssetEvents::PROCESSING_STARTED,
			DigitalAssetEvents::PROCESSING_COMPLETED,
		], $events);
	}

	/** @param list<string> $events */
	private function createManager(DigitalAssetProcessorInterface $processor, array &$events, AssetWorkflowRun $run): DigitalAssetWorkflowManager
	{
		$events = [];
		$dispatcher = $this->createStub(EventDispatcherInterface::class);
		$dispatcher->method('dispatch')->willReturnCallback(function (object $event, ?string $name) use (&$events) {
			$events[] = $name;

			return $event;
		});
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$runRepository = $this->createStub(EntityRepository::class);
		$runRepository->method('findOneBy')->willReturn($run);
		$entityManager->method('getRepository')->willReturn($runRepository);
		$assetRepository = $this->createStub(DigitalAssetRepository::class);
		$assetRepository->method('find')->willReturnCallback(function (int|string $id): UploadedDigitalAsset {
			return $this->asset;
		});
		$workflowRepository = $this->createStub(AssetWorkflowDefinitionRepository::class);
		$workflowRepository->method('findBy')->willReturn([$this->workflow]);

		return new DigitalAssetWorkflowManager(
			$entityManager,
			$assetRepository,
			$workflowRepository,
			new DigitalAssetProcessorRegistry([$processor]),
			$dispatcher,
			StateMachineFactory::wire(new AssetProcessingStateMachine()),
			StateMachineFactory::wire(new AssetRunStateMachine()),
		);
	}
}

final class NoopProcessor implements DigitalAssetProcessorInterface
{
	public function getKey(): string
	{
		return 'noop';
	}

	public function supportsMimePattern(string $mimePattern): bool
	{
		return true;
	}

	public function process(AbstractDigitalAsset $asset, AssetWorkflowDefinition $workflow): void
	{
	}
}

final class ThrowingProcessor implements DigitalAssetProcessorInterface
{
	public function __construct(private readonly string $message)
	{
	}

	public function getKey(): string
	{
		return 'throwing';
	}

	public function supportsMimePattern(string $mimePattern): bool
	{
		return true;
	}

	public function process(AbstractDigitalAsset $asset, AssetWorkflowDefinition $workflow): void
	{
		throw new \RuntimeException($this->message);
	}
}
