<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Workflow;

use iikiti\CMS\Web\Workflow\WorkflowSchemaExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type as Type;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;

final class WorkflowSchemaExtractorTest extends TestCase
{
	private FormFactoryInterface $factory;

	protected function setUp(): void
	{
		$this->factory = Forms::createFormFactoryBuilder()
			->addType(new StubWorkflowType())
			->getFormFactory();
	}

	public function testExtractProducesFieldSchema(): void
	{
		$form = $this->factory->create(StubWorkflowType::class);
		$fields = (new WorkflowSchemaExtractor())->extract($form);

		$byKey = [];
		foreach ($fields as $f) {
			$byKey[$f['key']] = $f;
		}

		$this->assertArrayHasKey('name', $byKey);
		$this->assertSame('Name', $byKey['name']['label']);
		$this->assertTrue($byKey['name']['required']);
		$this->assertSame('text', $byKey['name']['type']);

		$this->assertArrayHasKey('role', $byKey);
		$this->assertSame('select', $byKey['role']['type']);
		$this->assertCount(2, $byKey['role']['options']);

		$this->assertArrayHasKey('subscribe', $byKey);
		$this->assertSame('toggle', $byKey['subscribe']['type']);
	}

	public function testPopulateSubmitsData(): void
	{
		$form = $this->factory->create(StubWorkflowType::class);
		(new WorkflowSchemaExtractor())->populate($form, ['name' => 'Tester']);

		$this->assertSame('Tester', $form->get('name')->getData());
	}
}

/**
 * @extends AbstractType<mixed>
 */
class StubWorkflowType extends AbstractType
{
	#[\Override]
	public function buildForm(FormBuilderInterface $builder, array $options): void
	{
		$builder
			->add('name', Type\TextType::class, ['label' => 'Name', 'required' => true])
			->add('role', Type\ChoiceType::class, [
				'choices' => ['Admin' => 'admin', 'Editor' => 'editor'],
				'multiple' => false,
			])
			->add('subscribe', Type\CheckboxType::class, ['label' => 'Subscribe']);
	}
}
