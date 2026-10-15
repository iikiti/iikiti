<?php

namespace iikiti\CMS\Tests\Workflow;

use iikiti\CMS\Tests\Fixtures\StubWorkflowStep;
use iikiti\CMS\Workflow\FormStepInterface;
use iikiti\CMS\Workflow\MultiStepFormRunner;
use iikiti\CMS\Workflow\RuntimeWorkflowInterface;
use iikiti\CMS\Workflow\Workflow;
use iikiti\CMS\Workflow\WorkflowFactory;
use iikiti\CMS\Workflow\WorkflowGraphFactoryInterface;
use iikiti\CMS\Workflow\WorkflowManager;
use iikiti\CMS\Workflow\WorkflowSessionStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class MultiStepFormRunnerTest extends TestCase
{
	private WorkflowSessionStorage $storage;
	private MultiStepFormRunner $runner;

	protected function setUp(): void
	{
		$session = new Session(new MockArraySessionStorage());
		$requestStack = $this->createStub(RequestStack::class);
		$requestStack->method('getSession')->willReturn($session);
		$this->storage = new WorkflowSessionStorage($requestStack);

		$manager = new WorkflowManager(new EventDispatcher());
		$this->runner = new MultiStepFormRunner(
			$manager,
			new WorkflowFactory($manager),
			$this->storage,
			$this->createStub(FormFactoryInterface::class)
		);
	}

	public function testCreatePersistsInitialState(): void
	{
		$workflow = $this->runner->create('checkout', 'checkout');

		$this->assertSame('checkout', $workflow->getName());
		$this->assertTrue($this->storage->has('checkout'));
	}

	public function testDatabaseGraphCanRunInSessionWithPersistedMarking(): void
	{
		$graph = [
			'places' => [
				['id' => 'personal', 'metadata' => ['form' => ['fields' => [['name' => 'full_name', 'type' => 'text', 'label' => 'Full name', 'required' => true]]]]],
				'complete',
			],
			'transitions' => [['name' => 'finish_personal', 'from' => 'personal', 'to' => 'complete']],
			'initialPlaces' => ['personal'],
			'finalPlaces' => ['complete'],
			'metadata' => ['policyKey' => 'core.site_workflow'],
		];
		$workflow = $this->runner->create('db_form', 'db__personal', [WorkflowGraphFactoryInterface::CONTEXT_GRAPH_KEY => $graph]);

		self::assertInstanceOf(RuntimeWorkflowInterface::class, $workflow);
		self::assertInstanceOf(FormStepInterface::class, $workflow->getCurrentStep());
		self::assertSame('personal', $workflow->getCurrentStep()->getId());

		$restored = $this->runner->load('db_form');
		self::assertInstanceOf(RuntimeWorkflowInterface::class, $restored);
		self::assertSame(['personal' => 1], $restored->getWorkflowMarking());
		self::assertSame('personal', $restored->getCurrentStep()?->getId());
	}

	public function testLoadReturnsNullWhenNothingStored(): void
	{
		$this->assertNull($this->runner->load('missing'));
	}

	public function testSubmitPersistsWorkflowUntilComplete(): void
	{
		$workflow = new Workflow('checkout', new EventDispatcher(), [
			new StubWorkflowStep('address'),
			new StubWorkflowStep('payment'),
		]);

		$this->assertTrue($this->runner->submit('checkout', $workflow, ['city' => 'Lisbon']));
		$this->assertSame('payment', $workflow->getCurrentStep()?->getId());
		$this->assertNotNull($this->storage->load('checkout'));
	}

	public function testSubmitRejectionDoesNotPersist(): void
	{
		$workflow = new Workflow('checkout', new EventDispatcher(), [new StubWorkflowStep('address', false)]);

		$this->assertFalse($this->runner->submit('checkout', $workflow, []));
		$this->assertNull($this->storage->load('checkout'));
	}

	public function testSubmitRemovesPersistedStateWhenComplete(): void
	{
		$workflow = new Workflow('checkout', new EventDispatcher(), [new StubWorkflowStep('address')]);

		$this->assertTrue($this->runner->submit('checkout', $workflow, []));
		$this->assertTrue($workflow->isComplete());
	}
}
