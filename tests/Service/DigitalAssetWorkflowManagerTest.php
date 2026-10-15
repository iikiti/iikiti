<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\Workflow\WorkflowDefinitionRepository;
use iikiti\CMS\Service\DigitalAssetProcessorInterface;
use iikiti\CMS\Service\DigitalAssetProcessorRegistry;
use iikiti\CMS\Service\DigitalAssetWorkflowManager;
use iikiti\CMS\StateMachine\AssetProcessingStateMachine;
use iikiti\CMS\Tests\Support\StateMachineFactory;
use iikiti\CMS\Workflow\Runtime\AppliedWorkflowTransition;
use iikiti\CMS\Workflow\Runtime\WorkflowInstanceManagerInterface;
use iikiti\CMS\Workflow\Runtime\WorkflowTransitionResult;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DigitalAssetWorkflowManagerTest extends TestCase
{
	private UploadedDigitalAsset $asset;
	private WorkflowDefinition $definition;
	private WorkflowDefinitionVersion $version;
	private WorkflowInstance $instance;

	protected function setUp(): void
	{
		$this->asset = new UploadedDigitalAsset('Notes', 'text/plain', str_repeat('c', 64));
		$assetId = new \ReflectionProperty(\iikiti\CMS\Entity\DbObject::class, 'id');
		$assetId->setValue($this->asset, 9);
		$this->asset->setSize(20);
		$this->definition = new WorkflowDefinition('plain-text', 'Plain text', 'core', 'core');
		$definitionId = new \ReflectionProperty(WorkflowDefinition::class, 'id');
		$definitionId->setValue($this->definition, 12);
		$this->version = new WorkflowDefinitionVersion($this->definition, 1, $this->assetGraph());
		$this->instance = new WorkflowInstance($this->version, subjectType: 'digital_asset', subjectId: 9);
	}

	public function testSuccessfulAssetProcessingUsesGenericInstanceTransitions(): void
	{
		$events = [];
		$transitions = [];
		$processor = new TestAssetProcessor();
		$manager = $this->createManager($processor, $events, $transitions);

		$manager->process(9);

		self::assertSame(['start', 'complete'], $transitions);
		self::assertSame(['complete' => 1], $this->instance->getMarking());
		self::assertSame(1, $this->instance->getAttempts());
		self::assertSame('complete', $this->asset->getProcessingStatus());
		self::assertSame([
			\iikiti\CMS\Event\DigitalAssetEvents::PROCESSING_STARTED,
			\iikiti\CMS\Event\DigitalAssetEvents::PROCESSING_COMPLETED,
		], $events);
		self::assertSame(1, $processor->calls);
	}

	public function testProcessorFailureReloadsTheDetachedInstanceOnTheFailurePath(): void
	{
		// Workflow-transaction rollbacks clear the shared EntityManager and the
		// failure path must reload the instance (~ managedInstance()) before
		// bookkeeping, otherwise lock() would throw on the detached subject.
		$events = [];
		$transitions = [];
		$instanceId = new \ReflectionProperty(WorkflowInstance::class, 'id');
		$instanceId->setValue($this->instance, 5);
		$reloadedInstance = new WorkflowInstance($this->version, subjectType: 'digital_asset', subjectId: 9);
		$reloadedInstance->setMarking(['running' => 1]);
		$processor = new TestAssetProcessor('processor failed');
		$manager = $this->createManager($processor, $events, $transitions, null, static fn (): WorkflowInstance => $reloadedInstance);

		try {
			$manager->process(9);
			self::fail('Processor errors must propagate after recording failure.');
		} catch (\RuntimeException) {
		}

		self::assertSame(['start', 'fail'], $transitions);
		self::assertSame(['failed' => 1], $reloadedInstance->getMarking(), 'The failure must be recorded on the reloaded instance.');
		self::assertSame('processor failed', $reloadedInstance->getLastError());
		self::assertSame(['running' => 1], $this->instance->getMarking(), 'The detached in-memory instance must not receive the failure.');
		self::assertSame('failed', $this->asset->getProcessingStatus());
		self::assertContains(\iikiti\CMS\Event\DigitalAssetEvents::PROCESSING_FAILED, $events);
	}

	public function testConditionMismatchPersistsSkippedInstanceWithoutRunningProcessor(): void
	{
		$this->replaceAssetGraph(['min_size' => 1000]);
		$events = [];
		$transitions = [];
		$processor = new TestAssetProcessor();
		$manager = $this->createManager($processor, $events, $transitions);

		$manager->process(9);

		self::assertSame(['start', 'skip'], $transitions);
		self::assertSame(['skipped' => 1], $this->instance->getMarking());
		self::assertSame(1, $this->instance->getAttempts());
		self::assertSame('complete', $this->asset->getProcessingStatus());
		self::assertSame(0, $processor->calls);
	}

	public function testProcessorFailureFailsRunAndAggregateAssetThenRethrows(): void
	{
		$events = [];
		$transitions = [];
		$processor = new TestAssetProcessor('processor failed');
		$manager = $this->createManager($processor, $events, $transitions);

		try {
			$manager->process(9);
			self::fail('Processor errors must propagate after recording failure.');
		} catch (\RuntimeException $exception) {
			self::assertSame('processor failed', $exception->getMessage());
		}

		self::assertSame(['start', 'fail'], $transitions);
		self::assertSame(['failed' => 1], $this->instance->getMarking());
		self::assertSame('processor failed', $this->instance->getLastError());
		self::assertSame('failed', $this->asset->getProcessingStatus());
		self::assertContains(\iikiti\CMS\Event\DigitalAssetEvents::PROCESSING_FAILED, $events);
	}

	public function testCompletedInstanceIsNotProcessedTwice(): void
	{
		$this->instance->setMarking(['complete' => 1]);
		$events = [];
		$transitions = [];
		$processor = new TestAssetProcessor();
		$manager = $this->createManager($processor, $events, $transitions, $this->instance);

		$manager->process(9);

		self::assertSame([], $transitions);
		self::assertSame(0, $processor->calls);
		self::assertSame('complete', $this->asset->getProcessingStatus());
	}

	public function testFailedInstanceCanBeRetriedWithThePinnedDefinitionVersion(): void
	{
		$this->instance->setMarking(['failed' => 1]);
		$this->instance->setLastError('previous failure');
		$events = [];
		$transitions = [];
		$processor = new TestAssetProcessor();
		$manager = $this->createManager($processor, $events, $transitions, $this->instance);

		$manager->process(9);

		self::assertSame(['start', 'complete'], $transitions);
		self::assertSame(1, $this->instance->getAttempts());
		self::assertNull($this->instance->getLastError());
		self::assertSame(['complete' => 1], $this->instance->getMarking());
	}

	/** @param array<string,mixed> $conditions */
	private function replaceAssetGraph(array $conditions): void
	{
		$this->version = new WorkflowDefinitionVersion($this->definition, 1, $this->assetGraph($conditions));
		$this->instance = new WorkflowInstance($this->version, subjectType: 'digital_asset', subjectId: 9);
	}

	/**
	 * @param array<string,mixed> $conditions
	 *
	 * @return array<string,mixed>
	 */
	private function assetGraph(array $conditions = []): array
	{
		return [
			'type' => 'workflow',
			'places' => ['pending', 'running', 'complete', 'failed', 'skipped'],
			'transitions' => [
				['name' => 'start', 'from' => ['pending', 'running', 'failed', 'skipped'], 'to' => ['running'], 'metadata' => ['incrementAttempts' => true, 'clearLastError' => true, 'assetOperation' => 'start']],
				['name' => 'complete', 'from' => 'running', 'to' => 'complete', 'metadata' => ['clearLastError' => true, 'assetOperation' => 'complete']],
				['name' => 'fail', 'from' => 'running', 'to' => 'failed', 'metadata' => ['setLastError' => true, 'assetOperation' => 'fail']],
				['name' => 'skip', 'from' => 'running', 'to' => 'skipped', 'metadata' => ['clearLastError' => true, 'assetOperation' => 'skip']],
			],
			'initialPlaces' => ['pending'],
			'finalPlaces' => ['complete'],
			'metadata' => [
				'policyKey' => 'core.asset_processor',
				'assetProcessor' => [
					'processorKey' => 'test_processor',
					'mimePattern' => 'text/plain',
					'conditions' => $conditions,
					'options' => [],
					'allSites' => true,
				],
			],
		];
	}

	/**
	 * @param list<string>                               $events
	 * @param list<string>                               $transitions
	 * @param callable(int|string):WorkflowInstance|null $findOverride
	 */
	private function createManager(TestAssetProcessor $processor, array &$events, array &$transitions, ?WorkflowInstance $existingInstance = null, ?callable $findOverride = null): DigitalAssetWorkflowManager
	{
		$events = [];
		$transitions = [];
		$eventDispatcher = $this->createStub(EventDispatcherInterface::class);
		$eventDispatcher->method('dispatch')->willReturnCallback(static function (object $event, ?string $eventName) use (&$events): object {
			$events[] = (string) $eventName;

			return $event;
		});
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$versionRepository = $this->createStub(EntityRepository::class);
		$versionRepository->method('findOneBy')->willReturn($this->version);
		$entityManager->method('getRepository')->willReturn($versionRepository);
		$assetRepository = $this->createStub(DigitalAssetRepository::class);
		$assetRepository->method('find')->willReturn($this->asset);
		$workflowRepository = $this->createStub(WorkflowDefinitionRepository::class);
		$workflowRepository->method('findEnabled')->willReturn([$this->definition]);
		$instanceManager = $this->createStub(WorkflowInstanceManagerInterface::class);
		$instanceManager->method('findBySubject')->willReturn(null === $existingInstance ? [] : [$existingInstance]);
		$instanceManager->method('findByIdempotencyKey')->willReturn($existingInstance);
		$instanceManager->method('start')->willReturn($this->instance);
		if (null !== $findOverride) {
			$instanceManager->method('find')->willReturnCallback($findOverride);
		} else {
			$instanceManager->method('find')->willReturn($this->instance);
		}
		$instanceManager->method('getEnabledTransitionMetadata')->willReturnCallback(function (WorkflowInstance $instance): array {
			return $this->transitionMetadataFor($instance->getMarking());
		});
		$instanceManager->method('applyTransition')->willReturnCallback(function (WorkflowInstance $instance, string $transitionName, ?\iikiti\CMS\Entity\Object\User $actor, array $context = [], ?string $errorMessage = null) use (&$transitions): WorkflowTransitionResult {
			return $this->applyStubTransition($instance, $transitionName, $errorMessage, $transitions);
		});

		return new DigitalAssetWorkflowManager(
			$entityManager,
			$assetRepository,
			$workflowRepository,
			new DigitalAssetProcessorRegistry([$processor]),
			$instanceManager,
			$eventDispatcher,
			StateMachineFactory::wire(new AssetProcessingStateMachine()),
		);
	}

	/** @param array<string,int> $marking
	 * @return list<array{name:string,metadata:array<string,mixed>}>
	 */
	private function transitionMetadataFor(array $marking): array
	{
		$status = array_key_first($marking);
		if ('complete' === $status) {
			return [];
		}

		if ('running' === $status) {
			return [
				['name' => 'complete', 'metadata' => ['assetOperation' => 'complete']],
				['name' => 'fail', 'metadata' => ['assetOperation' => 'fail']],
				['name' => 'skip', 'metadata' => ['assetOperation' => 'skip']],
			];
		}

		return [['name' => 'start', 'metadata' => ['assetOperation' => 'start']]];
	}

	/**
	 * @param list<string> $transitions
	 */
	private function applyStubTransition(WorkflowInstance $instance, string $transitionName, ?string $errorMessage, array &$transitions): WorkflowTransitionResult
	{
		$from = $instance->getMarking();
		$transitions[] = $transitionName;
		$to = match ($transitionName) {
			'start' => ['running' => 1],
			'complete' => ['complete' => 1],
			'fail' => ['failed' => 1],
			'skip' => ['skipped' => 1],
			default => throw new \LogicException('Unexpected asset processor transition.'),
		};
		$instance->setMarking($to);
		if ('start' === $transitionName) {
			$instance->recordAttempt();
			$instance->setLastError(null);
		} elseif ('fail' === $transitionName) {
			$instance->setLastError($errorMessage);
		} else {
			$instance->setLastError(null);
		}

		return new WorkflowTransitionResult($from, $to, [new AppliedWorkflowTransition($transitionName, array_keys($from), array_keys($to), [], $from, $to)], in_array($transitionName, ['complete', 'fail', 'skip'], true));
	}
}

final class TestAssetProcessor implements DigitalAssetProcessorInterface
{
	public int $calls = 0;

	public function __construct(private readonly ?string $failure = null)
	{
	}

	public function getKey(): string
	{
		return 'test_processor';
	}

	public function supportsMimePattern(string $mimePattern): bool
	{
		return 'text/plain' === $mimePattern;
	}

	public function process(AbstractDigitalAsset $asset, AssetWorkflowDefinition $workflow): void
	{
		++$this->calls;
		if (null !== $this->failure) {
			throw new \RuntimeException($this->failure);
		}
	}
}
