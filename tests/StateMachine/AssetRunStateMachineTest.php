<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\StateMachine;

use iikiti\CMS\Entity\AssetWorkflowDefinition;
use iikiti\CMS\Entity\AssetWorkflowRun;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\StateMachine\AssetRunStateMachine;
use iikiti\CMS\Tests\Support\StateMachineFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;

final class AssetRunStateMachineTest extends TestCase
{
	private AssetRunStateMachine $machine;

	protected function setUp(): void
	{
		$this->machine = StateMachineFactory::wire(new AssetRunStateMachine());
	}

	private function createRun(): AssetWorkflowRun
	{
		return new AssetWorkflowRun(
			new UploadedDigitalAsset('Test', 'text/plain', str_repeat('c', 64)),
			new AssetWorkflowDefinition('unit-flow', 'Unit', 'image_thumbnail', 'text/*'),
		);
	}

	public function testStartTransitionsPendingRunToRunningAndCountsAttempts(): void
	{
		$run = $this->createRun();
		self::assertSame('pending', $run->getStatus());

		$this->machine->start($run);

		self::assertSame('running', $run->getStatus());
		self::assertSame(1, $run->getAttempts());
		self::assertNull($run->getLastError());
	}

	public function testStartIsReentrantFromEveryOpenPlace(): void
	{
		$run = $this->createRun();

		$this->machine->start($run);
		self::assertSame('running', $run->getStatus());
		self::assertSame(1, $run->getAttempts());

		$this->machine->start($run);
		self::assertSame('running', $run->getStatus());
		self::assertSame(2, $run->getAttempts());

		$this->machine->fail($run, 'boom');
		self::assertSame('failed', $run->getStatus());
		$this->machine->start($run);
		self::assertSame(3, $run->getAttempts());

		$this->machine->skip($run);
		self::assertSame('skipped', $run->getStatus());
		$this->machine->start($run);
		self::assertSame(4, $run->getAttempts());
	}

	public function testCompleteIsRefusedBeforeStart(): void
	{
		$run = $this->createRun();

		try {
			$this->machine->complete($run);
			self::fail('Completing a run that never started must be refused.');
		} catch (NotEnabledTransitionException) {
			self::assertSame('pending', $run->getStatus());
		}
	}

	public function testSkipIsRefusedBeforeStart(): void
	{
		$run = $this->createRun();

		try {
			$this->machine->skip($run);
			self::fail('Skipping a run that never started must be refused.');
		} catch (NotEnabledTransitionException) {
			self::assertSame('pending', $run->getStatus());
		}
	}

	public function testFailIsRefusedBeforeStart(): void
	{
		$run = $this->createRun();

		try {
			$this->machine->fail($run, 'boom');
			self::fail('Failing a run that never started must be refused.');
		} catch (NotEnabledTransitionException) {
			self::assertSame('pending', $run->getStatus());
		}
	}

	public function testCompleteTransitionsRunningRunToComplete(): void
	{
		$run = $this->createRun();
		$this->machine->start($run);

		$this->machine->complete($run);

		self::assertSame('complete', $run->getStatus());
		self::assertSame(1, $run->getAttempts());
	}

	public function testFailRecordsTruncatedErrorFromRunning(): void
	{
		$run = $this->createRun();
		$this->machine->start($run);

		$this->machine->fail($run, str_repeat('x', 2500));

		self::assertSame('failed', $run->getStatus());
		self::assertSame(2000, mb_strlen((string) $run->getLastError()));
	}

	public function testStartAfterFailClearsLastError(): void
	{
		$run = $this->createRun();
		$this->machine->start($run);
		$this->machine->fail($run, 'boom');

		$this->machine->start($run);

		self::assertNull($run->getLastError());
	}

	public function testCompletedRunCannotRestart(): void
	{
		$run = $this->createRun();
		$this->machine->start($run);
		$this->machine->complete($run);

		try {
			$this->machine->start($run);
			self::fail('A completed run must not restart.');
		} catch (NotEnabledTransitionException) {
			self::assertSame('complete', $run->getStatus());
			self::assertSame(1, $run->getAttempts());
		}
	}
}
