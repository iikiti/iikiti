<?php

namespace iikiti\CMS\Tests\Cache;

use iikiti\CMS\Cache\CachingStrategyRegistry;
use iikiti\CMS\Cache\Strategy\NoCacheStrategy;
use PHPUnit\Framework\TestCase;

final class CachingStrategyRegistryTest extends TestCase
{
	public function testRegistersStrategiesFromConstructorIterable(): void
	{
		$noCache = new NoCacheStrategy();
		$registry = new CachingStrategyRegistry([$noCache], 'none');

		$this->assertTrue($registry->hasStrategy('none'));
		$this->assertSame($noCache, $registry->getStrategy('none'));
		$this->assertSame('none', $registry->getDefaultStrategy());
	}

	public function testRuntimeRegistration(): void
	{
		$registry = new CachingStrategyRegistry();
		$this->assertFalse($registry->hasStrategy('none'));

		$noCache = new NoCacheStrategy();
		$registry->register('none', $noCache);

		$this->assertTrue($registry->hasStrategy('none'));
		$this->assertSame($noCache, $registry->getStrategy('none'));
	}

	public function testRuntimeRegistrationReplacesExisting(): void
	{
		$registry = new CachingStrategyRegistry();

		$first = new NoCacheStrategy();
		$second = new NoCacheStrategy();
		$registry->register('none', $first);
		$registry->register('none', $second);

		$this->assertSame($second, $registry->getStrategy('none'));
	}

	public function testUnregister(): void
	{
		$registry = new CachingStrategyRegistry();
		$registry->register('none', new NoCacheStrategy());

		$registry->unregister('none');

		$this->assertFalse($registry->hasStrategy('none'));
		$this->assertNull($registry->getStrategy('none'));
	}

	public function testGetStrategyReturnsNullForUnknown(): void
	{
		$registry = new CachingStrategyRegistry();
		$this->assertNull($registry->getStrategy('unknown'));
	}

	public function testSetDefaultStrategy(): void
	{
		$registry = new CachingStrategyRegistry([], 'doctrine_result_cache');
		$this->assertSame('doctrine_result_cache', $registry->getDefaultStrategy());

		$registry->setDefaultStrategy('none');
		$this->assertSame('none', $registry->getDefaultStrategy());
	}

	public function testGetAvailableStrategiesReturnsMetadata(): void
	{
		$registry = new CachingStrategyRegistry([new NoCacheStrategy()], 'none');

		$available = $registry->getAvailableStrategies();
		$this->assertCount(1, $available);

		$metadata = $available[0];
		$this->assertSame('none', $metadata->name);
		$this->assertSame('Disabled', $metadata->label);
		$this->assertSame([], $metadata->capabilities);
	}
}
