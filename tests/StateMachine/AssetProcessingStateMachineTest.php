<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\StateMachine;

use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\StateMachine\AssetProcessingStateMachine;
use iikiti\CMS\Tests\Support\StateMachineFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;

final class AssetProcessingStateMachineTest extends TestCase
{
	private AssetProcessingStateMachine $machine;

	protected function setUp(): void
	{
		$this->machine = StateMachineFactory::wire(new AssetProcessingStateMachine());
	}

	private function createAsset(): UploadedDigitalAsset
	{
		return new UploadedDigitalAsset('Test', 'text/plain', str_repeat('c', 64));
	}

	/** @return list<string> */
	private function allPlaces(): array
	{
		return ['pending', 'processing', 'complete', 'failed'];
	}

	public function testHappyPathReachesComplete(): void
	{
		$asset = $this->createAsset();
		self::assertSame('pending', $asset->getProcessingStatus());

		$this->machine->process($asset);

		self::assertSame('processing', $asset->getProcessingStatus());

		$this->machine->complete($asset);

		self::assertSame('complete', $asset->getProcessingStatus());
	}

	public function testProcessIsReentrantFromEveryPlace(): void
	{
		foreach ($this->allPlaces() as $status) {
			$asset = $this->createAsset();
			$asset->setProcessingStatus($status);

			$this->machine->process($asset);

			self::assertSame('processing', $asset->getProcessingStatus(), "process() from \"$status\" must stay enabled (messenger retry parity).");
		}
	}

	public function testRequeueReturnsEveryPlaceToPending(): void
	{
		foreach ($this->allPlaces() as $status) {
			$asset = $this->createAsset();
			$asset->setProcessingStatus($status);

			$this->machine->requeue($asset);

			self::assertSame('pending', $asset->getProcessingStatus(), "requeue() from \"$status\" must return to pending.");
		}
	}

	public function testFailTransitionsProcessingToFailed(): void
	{
		$asset = $this->createAsset();
		$this->machine->process($asset);

		$this->machine->fail($asset);

		self::assertSame('failed', $asset->getProcessingStatus());
	}

	public function testCompleteIsRefusedBeforeProcessing(): void
	{
		$asset = $this->createAsset();

		try {
			$this->machine->complete($asset);
			self::fail('Completing an asset that is not processing must be refused.');
		} catch (NotEnabledTransitionException) {
			self::assertSame('pending', $asset->getProcessingStatus());
		}
	}

	public function testFailIsRefusedOutsideProcessing(): void
	{
		$asset = $this->createAsset();

		try {
			$this->machine->fail($asset);
			self::fail('Failing an asset that is not processing must be refused.');
		} catch (NotEnabledTransitionException) {
			self::assertSame('pending', $asset->getProcessingStatus());
		}

		$this->machine->process($asset);
		$this->machine->complete($asset);

		try {
			$this->machine->fail($asset);
			self::fail('Failing a completed asset must be refused.');
		} catch (NotEnabledTransitionException) {
			self::assertSame('complete', $asset->getProcessingStatus());
		}
	}

	public function testSetterKeepsAcceptingInitialMarkingOutsideTransitions(): void
	{
		$asset = $this->createAsset();
		$asset->setProcessingStatus('complete');

		self::assertSame('complete', $asset->getProcessingStatus());
	}
}
