<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Render;

use iikiti\CMS\Web\BlockEditor\Render\RootContainerRule;
use PHPUnit\Framework\TestCase;

final class RootContainerRuleTest extends TestCase
{
	public function testOnlyContainerIsAllowedAtRoot(): void
	{
		self::assertTrue(RootContainerRule::isAllowedAtRoot('container'));
		self::assertFalse(RootContainerRule::isAllowedAtRoot('text'));
		self::assertFalse(RootContainerRule::isAllowedAtRoot('heading'));
		self::assertFalse(RootContainerRule::isAllowedAtRoot('image'));
	}

	public function testViolationsReportRootNonContainersPerRegion(): void
	{
		$violations = RootContainerRule::violations([
			'header' => [['type' => 'heading']],
			'main' => [
				['type' => 'container', 'children' => []],
				['type' => 'text'],
			],
		]);

		self::assertSame([
			['region' => 'header', 'index' => 0, 'type' => 'heading'],
			['region' => 'main', 'index' => 1, 'type' => 'text'],
		], $violations);
	}

	public function testValidTreeHasNoViolations(): void
	{
		self::assertSame([], RootContainerRule::violations([
			'main' => [['type' => 'container', 'children' => [['type' => 'text']]]],
		]));
	}
}
