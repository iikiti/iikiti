<?php

namespace iikiti\CMS\Tests\Workflow\StepProvider;

use iikiti\CMS\Workflow\Step\DynamicFormStep;
use iikiti\CMS\Workflow\StepProvider\DynamicFormStepProvider;
use iikiti\CMS\Workflow\Workflow;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class DynamicFormStepProviderTest extends TestCase
{
	public function testProvidesStepsFromContext(): void
	{
		$steps = $this->createProvider()->provideSteps($this->createWorkflow(), [
			DynamicFormStepProvider::CONTEXT_KEY => [
				['id' => 'personal', 'name' => 'Personal details', 'fields' => [['name' => 'full_name']]],
				['id' => 'contact', 'name' => 'Contact details', 'fields' => [['name' => 'email']]],
			],
		]);

		$this->assertCount(2, $steps);
		$this->assertInstanceOf(DynamicFormStep::class, $steps[0]);
		$this->assertSame('personal', $steps[0]->getId());
		$this->assertSame('contact', $steps[1]->getId());
	}

	public function testInvalidDefinitionsAreIgnored(): void
	{
		$steps = $this->createProvider()->provideSteps($this->createWorkflow(), [
			DynamicFormStepProvider::CONTEXT_KEY => [
				['id' => '', 'name' => 'Missing id', 'fields' => []],
				'not-an-array',
				['id' => 'valid', 'name' => 'Valid', 'fields' => []],
			],
		]);

		$this->assertCount(1, $steps);
		$this->assertSame('valid', $steps[0]->getId());
	}

	public function testSupportsOnlyContextsWithStepDefinitions(): void
	{
		$provider = $this->createProvider();

		$this->assertTrue($provider->supports([DynamicFormStepProvider::CONTEXT_KEY => []]));
		$this->assertFalse($provider->supports([]));
		$this->assertFalse($provider->supports('not-an-array'));
	}

	public function testWorkflowName(): void
	{
		$this->assertSame('dynamic_form', $this->createProvider()->getWorkflowName());
	}

	private function createProvider(): DynamicFormStepProvider
	{
		return new DynamicFormStepProvider();
	}

	private function createWorkflow(): Workflow
	{
		return new Workflow(DynamicFormStepProvider::WORKFLOW_NAME, new EventDispatcher());
	}
}
