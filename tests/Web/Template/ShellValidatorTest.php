<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Template;

use iikiti\CMS\Web\Template\Rule\SiteRule;
use iikiti\CMS\Web\Template\ShellValidator;
use PHPUnit\Framework\TestCase;

final class ShellValidatorTest extends TestCase
{
	private function validator(): ShellValidator
	{
		return new ShellValidator([new SiteRule()]);
	}

	public function testAcceptsContainerRootBlocksAndKnownRules(): void
	{
		$errors = $this->validator()->validate(
			'header',
			[['type' => 'container', 'id' => 'c1']],
			[['rule' => 'site', 'config' => ['site_id' => 1]]],
		);

		$this->assertSame([], $errors);
	}

	public function testRejectsNonContainerRootBlock(): void
	{
		$errors = $this->validator()->validate('header', [['type' => 'text', 'id' => 't1']], []);

		$this->assertNotEmpty($errors);
		$this->assertStringContainsString('text', $errors[0]);
	}

	public function testRejectsUnregisteredDisplayRule(): void
	{
		$errors = $this->validator()->validate(
			'footer',
			[['type' => 'container', 'id' => 'c1']],
			[['rule' => 'does_not_exist', 'config' => []]],
		);

		$this->assertNotEmpty($errors);
		$this->assertStringContainsString('does_not_exist', $errors[0]);
	}

	public function testRejectsUnknownRole(): void
	{
		$errors = $this->validator()->validate('sidebar', [], []);

		$this->assertNotEmpty($errors);
	}
}
