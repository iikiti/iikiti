<?php

namespace iikiti\CMS\Tests\Workflow;

use iikiti\CMS\Tests\Fixtures\StubWorkflowStep;
use iikiti\CMS\Workflow\Workflow;
use iikiti\CMS\Workflow\WorkflowSessionStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class WorkflowSessionStorageTest extends TestCase
{
	private WorkflowSessionStorage $storage;

	protected function setUp(): void
	{
		$session = new Session(new MockArraySessionStorage());
		$requestStack = $this->createStub(RequestStack::class);
		$requestStack->method('getSession')->willReturn($session);

		$this->storage = new WorkflowSessionStorage($requestStack);
	}

	public function testSaveLoadAndRemove(): void
	{
		$state = [
			'name' => 'checkout',
			'context' => ['step' => 1],
			'current_step' => 'address',
			'complete' => false,
		];

		$this->assertFalse($this->storage->has('checkout'));

		$this->storage->save('checkout', $state);

		$this->assertTrue($this->storage->has('checkout'));
		$this->assertSame($state, $this->storage->load('checkout'));

		$this->storage->remove('checkout');

		$this->assertFalse($this->storage->has('checkout'));
		$this->assertNull($this->storage->load('checkout'));
	}

	public function testLoadReturnsNullForMissingKey(): void
	{
		$this->assertNull($this->storage->load('unknown'));
	}

	public function testExtractStateCapturesWorkflowProgress(): void
	{
		$workflow = new Workflow('checkout', new EventDispatcher(), [new StubWorkflowStep('address')]);
		$workflow->mergeContext(['shipping' => 'express']);

		$this->assertSame(
			[
				'name' => 'checkout',
				'context' => ['shipping' => 'express'],
				'current_step' => 'address',
				'complete' => false,
			],
			WorkflowSessionStorage::extractState($workflow)
		);
	}
}
