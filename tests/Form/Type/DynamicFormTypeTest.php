<?php

namespace iikiti\CMS\Tests\Form\Type;

use iikiti\CMS\Form\Type\DynamicFormType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Forms;

final class DynamicFormTypeTest extends TestCase
{
	public function testBuildsConfiguredFields(): void
	{
		$form = Forms::createFormFactory()->create(DynamicFormType::class, null, [
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
		$form = Forms::createFormFactory()->create(DynamicFormType::class, null, [
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
}
