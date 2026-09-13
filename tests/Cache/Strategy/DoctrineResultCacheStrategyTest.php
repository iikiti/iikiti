<?php

namespace iikiti\CMS\Tests\Cache\Strategy;

use Doctrine\ORM\Query;
use iikiti\CMS\Cache\Strategy\DoctrineResultCacheStrategy;
use iikiti\CMS\Service\CacheState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DoctrineResultCacheStrategyTest extends TestCase
{
	private function createStrategy(
		bool $cacheStateEnabled = true,
		int $defaultTTL = 300,
	): DoctrineResultCacheStrategy {
		return new DoctrineResultCacheStrategy(
			new ArrayAdapter(),
			new CacheState($cacheStateEnabled),
			$defaultTTL
		);
	}

	public function testMetadata(): void
	{
		$strategy = $this->createStrategy();

		$this->assertSame('doctrine_result_cache', $strategy->getName());
		$this->assertSame('Doctrine ORM Result Cache', $strategy->getLabel());
		$this->assertNotSame('', $strategy->getDescription());
		$this->assertContains('query_level', $strategy->getCapabilities());
		$this->assertContains('ttl', $strategy->getCapabilities());
	}

	public function testIsEnabledWithCacheStateOn(): void
	{
		$strategy = $this->createStrategy(true);
		$this->assertTrue($strategy->isEnabled([]));
		$this->assertTrue($strategy->isEnabled(['cache' => true]));
		$this->assertFalse($strategy->isEnabled(['cache' => false]));
	}

	public function testIsEnabledWithCacheStateOff(): void
	{
		$strategy = $this->createStrategy(false);
		$this->assertFalse($strategy->isEnabled([]));
		$this->assertFalse($strategy->isEnabled(['cache' => true]));
	}

	public function testDecorateQueryEnablesResultCache(): void
	{
		$strategy = $this->createStrategy();

		/** @var Query<array-key,mixed>&\PHPUnit\Framework\MockObject\MockObject $query */
		$query = $this->createMock(Query::class);
		$query->expects($this->once())->
			method('setResultCache')->
			with($this->isInstanceOf(\Psr\Cache\CacheItemPoolInterface::class));
		$query->expects($this->once())->
			method('enableResultCache')->
			with(300, 'db_cache_key');

		$strategy->decorateQuery($query, 'db_cache_key', 300);
	}

	public function testDecorateQueryUsesDefaultTtlWhenNull(): void
	{
		$strategy = $this->createStrategy(true, 60);

		/** @var Query<array-key,mixed>&\PHPUnit\Framework\MockObject\MockObject $query */
		$query = $this->createMock(Query::class);
		$query->expects($this->once())->
			method('enableResultCache')->
			with(60, 'db_cache_key');

		$strategy->decorateQuery($query, 'db_cache_key', null);
	}

	public function testCacheResultInvokesCallback(): void
	{
		$strategy = $this->createStrategy();
		$result = $strategy->cacheResult('key', fn (): string => 'value', null, []);

		$this->assertSame('value', $result);
	}

	public function testClearDelegatesToPool(): void
	{
		$pool = new ArrayAdapter();
		$item = $pool->getItem('some_key');
		$item->set('value');
		$pool->save($item);

		$strategy = new DoctrineResultCacheStrategy($pool, new CacheState(true), 300);
		$strategy->clear();

		$this->assertFalse($pool->getItem('some_key')->isHit());
	}
}
