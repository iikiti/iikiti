<?php

namespace iikiti\CMS\Tests\Form\Type;

use iikiti\CMS\Form\Type\DynamicFormType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validation;

final class DynamicFormTypeTest extends TestCase
{
	public function testBuildsConfiguredFields(): void
	{
		$form = $this->createFactory()->create(DynamicFormType::class, null, [
			'fields' => [
				['name' => 'full_name'],
				['name' => 'email', 'type' => EmailType::class],
			],
		]);

		$this->assertTrue($form->has('full_name'));
		$this->assertTrue($form->has('email'));
		$this->assertInstanceOf(
			EmailType::class,
			$form->get('email')->getConfig()->getType()->getInnerType()
		);
	}

	public function testInvalidDefinitionsAreSkippedOrFallBackToText(): void
	{
		$form = $this->createFactory()->create(DynamicFormType::class, null, [
			'fields' => [
				['name' => ''],
				'not-an-array',
				['name' => 'notes', 'type' => 'NotARealFormType'],
			],
		]);

		$this->assertFalse($form->has(''));
		$this->assertTrue($form->has('notes'));
		$this->assertInstanceOf(
			TextType::class,
			$form->get('notes')->getConfig()->getType()->getInnerType()
		);
	}

	public function testUnregisteredFormTypesAndOptionsAreIgnored(): void
	{
		$form = $this->createFactory()->create(DynamicFormType::class, null, [
			'fields' => [
				[
					'name' => 'safe_field',
					'type' => HiddenType::class,
					'options' => [
						'data' => 'untrusted default',
						'required' => false,
						'attr' => ['onfocus' => 'untrusted'],
					],
				],
			],
		]);

		$field = $form->get('safe_field');
		self::assertInstanceOf(TextType::class, $field->getConfig()->getType()->getInnerType());
		self::assertNull($field->getConfig()->getOption('data'));
		self::assertSame([], $field->getConfig()->getOption('attr'));
		self::assertFalse($field->getConfig()->getOption('required'));
	}

	private function createFactory(): \Symfony\Component\Form\FormFactoryInterface
	{
		return Forms::createFormFactoryBuilder()
			->addType(new DynamicFormType())
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->addExtension(new CsrfExtension($this->createStub(CsrfTokenManagerInterface::class)))
			->getFormFactory();
	}
}
