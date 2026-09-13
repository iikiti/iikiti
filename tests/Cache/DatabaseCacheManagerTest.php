<?php

namespace iikiti\CMS\Tests\Cache;

use Doctrine\ORM\Query;
use iikiti\CMS\Cache\CachingStrategyRegistry;
use iikiti\CMS\Cache\Strategy\DoctrineResultCacheStrategy;
use iikiti\CMS\Cache\Strategy\NoCacheStrategy;
use iikiti\CMS\Service\CacheState;
use iikiti\CMS\Service\DatabaseCacheManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DatabaseCacheManagerTest extends TestCase
{
	private const ENTITY = 'iikiti\CMS\Entity\Object\User';

	private ArrayAdapter $pool;

	protected function setUp(): void
	{
		$this->pool = new ArrayAdapter();
	}

	private function createManager(
		bool $cacheStateEnabled = true,
		?string $overrideStrategy = null,
		int $defaultTTL = 300,
	): DatabaseCacheManager {
		$cacheState = new CacheState($cacheStateEnabled);
		$cacheState->setOverrideStrategy($overrideStrategy);
		$registry = new CachingStrategyRegistry(
			[
				new DoctrineResultCacheStrategy($this->pool, $cacheState, $defaultTTL),
				new NoCacheStrategy(),
			]
		);

		return new DatabaseCacheManager($this->pool, $cacheState, $registry, $defaultTTL);
	}

	public function testGenerationStartsAtOne(): void
	{
		$manager = $this->createManager();
		$this->assertSame(1, $manager->getGeneration(self::ENTITY));
	}

	public function testBumpGenerationIncrements(): void
	{
		$manager = $this->createManager();
		$manager->bumpGeneration(self::ENTITY);
		$this->assertSame(2, $manager->getGeneration(self::ENTITY));

		$manager->bumpGeneration(self::ENTITY);
		$this->assertSame(3, $manager->getGeneration(self::ENTITY));
	}

	public function testGenerateCacheKeyIncludesGenerationAndSite(): void
	{
		$manager = $this->createManager();

		$key = $manager->generateCacheKey(self::ENTITY, 'findByProperty', [
			'name' => 'username',
			'value' => 'jane',
		]);

		$this->assertStringStartsWith('db_cache:v1:', $key);
		$this->assertStringContainsString('User', $key);
		$this->assertStringContainsString(':0', strrchr($key, ':'));
	}

	public function testGenerateCacheKeyChangesAfterInvalidate(): void
	{
		$manager = $this->createManager();
		$context = ['name' => 'username', 'value' => 'jane'];

		$before = $manager->generateCacheKey(self::ENTITY, 'findByProperty', $context);
		$manager->invalidate(self::ENTITY);
		$after = $manager->generateCacheKey(self::ENTITY, 'findByProperty', $context);

		$this->assertNotSame($before, $after);
		$this->assertStringStartsWith('db_cache:v2:', $after);
	}

	public function testDecorateQueryAppliesCacheWhenEnabled(): void
	{
		$manager = $this->createManager();

		/** @var Query<array-key,mixed>&\PHPUnit\Framework\MockObject\MockObject $query */
		$query = $this->createMock(Query::class);
		$query->expects($this->once())->method('setResultCache');
		$query->expects($this->once())->
			method('enableResultCache')->
			with(300, $this->stringStartsWith('db_cache:v1:'));

		$manager->decorateQuery($query, self::ENTITY, [
			'operation' => 'findOneBy',
			'options' => [],
			'criteria' => ['token' => 'abc'],
		]);
	}

	public function testDecorateQuerySkippedWhenDisabled(): void
	{
		$manager = $this->createManager(false);

		/** @var Query<array-key,mixed>&\PHPUnit\Framework\MockObject\MockObject $query */
		$query = $this->createMock(Query::class);
		$query->expects($this->never())->method('enableResultCache');

		$manager->decorateQuery($query, self::ENTITY, [
			'operation' => 'findOneBy',
			'options' => [],
		]);
	}

	public function testDecorateQuerySkippedWhenCacheOptionFalse(): void
	{
		$manager = $this->createManager();

		/** @var Query<array-key,mixed>&\PHPUnit\Framework\MockObject\MockObject $query */
		$query = $this->createMock(Query::class);
		$query->expects($this->never())->method('enableResultCache');

		$manager->decorateQuery($query, self::ENTITY, [
			'operation' => 'findOneBy',
			'options' => ['cache' => false],
		]);
	}

	public function testResolveTtlUsesOptionOrDefault(): void
	{
		$manager = $this->createManager(defaultTTL: 60);

		$this->assertSame(60, $manager->resolveTtl([]));
		$this->assertSame(120, $manager->resolveTtl(['cacheTTL' => 120]));
		$this->assertSame(60, $manager->resolveTtl(['cacheTTL' => 'nope']));
	}

	public function testCacheResultWithMethodLevelStrategy(): void
	{
		$manager = $this->createManager(overrideStrategy: 'none');
		$invocations = 0;
		$result = $manager->cacheResult(
			self::ENTITY,
			'findBy',
			function () use (&$invocations) {
				++$invocations;

				return ['result'];
			},
			[]
		);

		$this->assertSame(['result'], $result);
		$this->assertSame(1, $invocations);
	}

	public function testGetAvailableStrategies(): void
	{
		$manager = $this->createManager();

		$available = $manager->getAvailableStrategies();
		$names = array_map(static fn ($metadata) => $metadata->name, $available);

		$this->assertContains('doctrine_result_cache', $names);
		$this->assertContains('none', $names);
	}
}
