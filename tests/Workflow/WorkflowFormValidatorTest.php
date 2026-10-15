<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Workflow;

use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Form\Type\DynamicFormType;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\Runtime\WorkflowFormValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validation;

final class WorkflowFormValidatorTest extends TestCase
{
	public function testValidatesAndReturnsOnlyConfiguredFormFields(): void
	{
		$instance = $this->createInstance();
		$validator = $this->createValidator();

		$values = $validator->validateTransitionData($instance, 'submit', ['decision' => 'approve']);

		self::assertSame(['decision' => 'approve'], $values);
	}

	public function testRejectsMissingRequiredFieldsAndExtraData(): void
	{
		$validator = $this->createValidator();
		$instance = $this->createInstance();

		try {
			$validator->validateTransitionData($instance, 'submit', ['decision' => '']);
			self::fail('A blank required field must be refused.');
		} catch (\InvalidArgumentException) {
			self::assertSame(['review' => 1], $instance->getMarking());
		}

		$this->expectException(\InvalidArgumentException::class);
		$validator->validateTransitionData($instance, 'submit', ['decision' => 'approve', 'unconfigured' => 'value']);
	}

	private function createValidator(): WorkflowFormValidator
	{
		$formFactory = Forms::createFormFactoryBuilder()->
			addType(new DynamicFormType())->
			addExtension(new ValidatorExtension(Validation::createValidator()))->
			addExtension(new CsrfExtension($this->createStub(CsrfTokenManagerInterface::class)))->
			getFormFactory();

		return new WorkflowFormValidator(new WorkflowGraphCompiler(), $formFactory);
	}

	private function createInstance(): WorkflowInstance
	{
		return new WorkflowInstance(new WorkflowDefinitionVersion(
			new WorkflowDefinition('site.review', 'Review', 'site', '7'),
			1,
			[
				'places' => [
					['id' => 'review', 'metadata' => ['form' => ['fields' => [['name' => 'decision', 'type' => 'text', 'label' => 'Decision', 'required' => true]]]]],
					'complete',
				],
				'transitions' => [['name' => 'submit', 'from' => 'review', 'to' => 'complete']],
				'initialPlaces' => ['review'],
				'finalPlaces' => ['complete'],
				'metadata' => ['policyKey' => 'core.site_workflow'],
			],
		));
	}
}
