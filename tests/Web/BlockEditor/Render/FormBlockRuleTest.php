<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Render;

use iikiti\CMS\Web\BlockEditor\Render\FormBlockRule;
use PHPUnit\Framework\TestCase;

final class FormBlockRuleTest extends TestCase
{
	public function testAllowsValidFormAndFieldsetHierarchy(): void
	{
		$tree = ['main' => [[
			'type' => 'container',
			'children' => [[
				'type' => 'form',
				'children' => [
					['type' => 'input'],
					['type' => 'button'],
					['type' => 'fieldset', 'children' => [
						['type' => 'legend'],
						['type' => 'checkbox'],
						['type' => 'button'],
					]],
				],
			]],
		]]];

		self::assertNull(FormBlockRule::firstViolation($tree));
	}

	public function testRejectsLegendOutsideFieldset(): void
	{
		$tree = ['main' => [['type' => 'container', 'children' => [[
			'type' => 'form',
			'children' => [['type' => 'legend']],
		]]]]];

		self::assertSame('legend_parent', FormBlockRule::firstViolation($tree)?->rule);
	}

	public function testRejectsDisallowedFormAndFieldsetChildren(): void
	{
		$formTree = ['main' => [['type' => 'container', 'children' => [[
			'type' => 'form',
			'children' => [['type' => 'container']],
		]]]]];
		$fieldsetTree = ['main' => [['type' => 'container', 'children' => [[
			'type' => 'fieldset',
			'children' => [['type' => 'container']],
		]]]]];

		self::assertSame('form_child', FormBlockRule::firstViolation($formTree)?->rule);
		self::assertSame('fieldset_child', FormBlockRule::firstViolation($fieldsetTree)?->rule);
	}

	public function testRejectsNestedForms(): void
	{
		$tree = ['main' => [['type' => 'container', 'children' => [[
			'type' => 'form',
			'children' => [['type' => 'form']],
		]]]]];

		self::assertSame('nested_form', FormBlockRule::firstViolation($tree)?->rule);
	}

	public function testRejectsLegendAfterControlsAndDuplicateLegend(): void
	{
		$lateLegend = ['main' => [['type' => 'container', 'children' => [[
			'type' => 'fieldset',
			'children' => [['type' => 'input'], ['type' => 'legend']],
		]]]]];
		$duplicateLegend = ['main' => [['type' => 'container', 'children' => [[
			'type' => 'fieldset',
			'children' => [['type' => 'legend'], ['type' => 'legend']],
		]]]]];

		self::assertSame('legend_position', FormBlockRule::firstViolation($lateLegend)?->rule);
		self::assertSame('duplicate_legend', FormBlockRule::firstViolation($duplicateLegend)?->rule);
	}
}
