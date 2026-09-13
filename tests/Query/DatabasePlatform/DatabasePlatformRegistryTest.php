<?php

namespace iikiti\CMS\Tests\Query\DatabasePlatform;

use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformRegistry;
use iikiti\CMS\Query\DatabasePlatform\PostgreSQL\PostgreSQLPlatformStrategy;
use iikiti\CMS\Tests\Query\CreatesQueryBuilders;
use PHPUnit\Framework\TestCase;

final class DatabasePlatformRegistryTest extends TestCase
{
	use CreatesQueryBuilders;

	public function testConstructorRegistersStrategies(): void
	{
		$registry = $this->createRegistry();

		$this->assertTrue($registry->has('postgresql'));
		$this->assertSame('postgresql', $registry->getDefaultStrategy());
	}

	public function testRegisterAndUnregister(): void
	{
		$registry = $this->createRegistry();
		$strategy = new PostgreSQLPlatformStrategy();

		$registry->register('custom', $strategy);
		$this->assertSame($strategy, $registry->get('custom'));

		$registry->unregister('custom');
		$this->assertFalse($registry->has('custom'));
		$this->assertNull($registry->get('custom'));
	}

	public function testResolveByNameAndFallback(): void
	{
		$registry = $this->createRegistry();

		$this->assertSame('postgresql', $registry->resolve()->getName());
		$this->assertSame('postgresql', $registry->resolve('unknown')->getName());
	}

	public function testResolveForConnectionDetectsPlatform(): void
	{
		$registry = $this->createRegistry();

		$this->assertSame('postgresql', $registry->resolveForConnection($this->createConnection())->getName());
	}

	public function testAllAndDescribe(): void
	{
		$registry = $this->createRegistry();

		$this->assertCount(1, $registry->all());
		$this->assertSame([['name' => 'postgresql', 'label' => 'PostgreSQL']], $registry->describe());
	}

	private function createRegistry(): DatabasePlatformRegistry
	{
		return new DatabasePlatformRegistry(
			[new PostgreSQLPlatformStrategy()],
			PostgreSQLPlatformStrategy::NAME
		);
	}
}
