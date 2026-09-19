<?php

namespace iikiti\CMS\Tests\Search\Strategy;

use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Enum\SearchIndexType;
use iikiti\CMS\Search\Strategy\SearchEngineInterface;
use iikiti\CMS\Search\Strategy\SearchEngineRegistry;
use PHPUnit\Framework\TestCase;

final class SearchEngineRegistryTest extends TestCase
{
	private SearchEngineRegistry $registry;

	private SearchEngineInterface $engine;

	protected function setUp(): void
	{
		$this->engine = $this->createStub(SearchEngineInterface::class);
		$this->engine->method('getName')->willReturn('test_engine');
		$this->engine->method('getLabel')->willReturn('Test Engine');

		$this->registry = new SearchEngineRegistry(
			[$this->engine],
			'default_engine'
		);
	}

	public function testGetRegisteredEngine(): void
	{
		self::assertTrue($this->registry->has('test_engine'));
		self::assertSame($this->engine, $this->registry->get('test_engine'));
	}

	public function testGetUnregisteredEngineReturnsNull(): void
	{
		self::assertNull($this->registry->get('nonexistent'));
		self::assertFalse($this->registry->has('nonexistent'));
	}

	public function testRegisterNewEngine(): void
	{
		$engine = $this->createStub(SearchEngineInterface::class);
		$engine->method('getName')->willReturn('custom_engine');
		$engine->method('getLabel')->willReturn('Custom Engine');

		$this->registry->register('custom_engine', $engine);

		self::assertTrue($this->registry->has('custom_engine'));
		self::assertSame($engine, $this->registry->get('custom_engine'));
	}

	public function testUnregisterEngine(): void
	{
		$this->registry->register('test_engine', $this->engine);
		$this->registry->unregister('test_engine');

		self::assertFalse($this->registry->has('test_engine'));
	}

	public function testResolveByName(): void
	{
		self::assertSame($this->engine, $this->registry->resolve('test_engine'));
	}

	public function testResolveFallsBackToDefault(): void
	{
		$engine = $this->createStub(SearchEngineInterface::class);
		$engine->method('getName')->willReturn('postgresql');
		$engine->method('getLabel')->willReturn('PostgreSQL');

		$registry = new SearchEngineRegistry([$engine], 'postgresql');
		self::assertSame($engine, $registry->resolve(null));
	}

	public function testResolveThrowsWhenNotFound(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('No search engine adapter registered for "nonexistent".');

		$this->registry->resolve('nonexistent');
	}

	public function testResolveForConfig(): void
	{
		$index = new SearchIndex('test', 'Test', SearchIndexType::Custom, 'test_engine');
		$this->registry->register('test_engine', $this->engine);

		self::assertSame($this->engine, $this->registry->resolveForConfig($index));
	}

	public function testGetDefaultEngine(): void
	{
		self::assertSame('default_engine', $this->registry->getDefaultEngine());
	}

	public function testSetDefaultEngine(): void
	{
		$this->registry->setDefaultEngine('new_default');

		self::assertSame('new_default', $this->registry->getDefaultEngine());
	}

	public function testAllReturnsRegisteredEngines(): void
	{
		$all = $this->registry->all();

		self::assertCount(1, $all);
		self::assertArrayHasKey('test_engine', $all);
		self::assertSame($this->engine, $all['test_engine']);
	}

	public function testDescribe(): void
	{
		$described = $this->registry->describe();

		self::assertCount(1, $described);
		self::assertSame('test_engine', $described[0]['name']);
		self::assertSame('Test Engine', $described[0]['label']);
	}
}
