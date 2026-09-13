<?php

namespace iikiti\CMS\Tests\Cache;

use iikiti\CMS\Service\CacheState;
use PHPUnit\Framework\TestCase;

final class CacheStateTest extends TestCase
{
	public function testEnabledByDefault(): void
	{
		$state = new CacheState();
		$this->assertTrue($state->isEnabled());
	}

	public function testConstructorEnablesState(): void
	{
		$state = new CacheState(false);
		$this->assertFalse($state->isEnabled());

		$state = new CacheState(true);
		$this->assertTrue($state->isEnabled());
	}

	public function testDisableAndEnable(): void
	{
		$state = new CacheState();
		$state->disable();

		$this->assertFalse($state->isEnabled());

		$state->enable();
		$this->assertTrue($state->isEnabled());
	}

	public function testStrategyOverride(): void
	{
		$state = new CacheState();
		$this->assertNull($state->getOverrideStrategy());

		$state->setOverrideStrategy('none');
		$this->assertSame('none', $state->getOverrideStrategy());

		$state->setOverrideStrategy(null);
		$this->assertNull($state->getOverrideStrategy());
	}

	public function testResetRestoresDefaults(): void
	{
		$state = new CacheState(false);
		$state->setOverrideStrategy('none');

		$state->reset();

		$this->assertTrue($state->isEnabled());
		$this->assertNull($state->getOverrideStrategy());
	}
}
