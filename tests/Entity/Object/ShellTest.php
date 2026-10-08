<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity\Object;

use Doctrine\Common\Collections\ArrayCollection;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Shell;
use PHPUnit\Framework\TestCase;

final class ShellTest extends TestCase
{
	private function shell(): Shell
	{
		$shell = new Shell();
		(new \ReflectionClass(DbObject::class))->getProperty('properties')->setValue($shell, new ArrayCollection());

		return $shell;
	}

	public function testDefaultsAreEnabledWithNoRulesOrBlocks(): void
	{
		$shell = $this->shell();

		$this->assertSame('', $shell->getRole());
		$this->assertSame(0, $shell->getPriority());
		$this->assertTrue($shell->isEnabled());
		$this->assertSame([], $shell->getDisplayRules());
		$this->assertSame([], $shell->getBlocks());
	}

	public function testRoleIsNormalisedAndPersisted(): void
	{
		$shell = $this->shell();
		$shell->setRole('header');
		$shell->setPriority(5);
		$shell->setEnabled(false);

		$this->assertSame('header', $shell->getRole());
		$this->assertSame(5, $shell->getPriority());
		$this->assertFalse($shell->isEnabled());
	}

	public function testRejectsUnknownRole(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$this->shell()->setRole('sidebar-typo');
	}

	public function testDisplayRulesRoundTrip(): void
	{
		$shell = $this->shell();
		$rules = [['rule' => 'site', 'config' => ['site_id' => 1]]];
		$shell->setDisplayRules($rules);

		$this->assertSame($rules, $shell->getDisplayRules());
	}
}
