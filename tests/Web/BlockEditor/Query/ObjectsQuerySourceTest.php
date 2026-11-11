<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Query;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Service\DatabaseCacheManager;
use iikiti\CMS\Web\BlockEditor\Query\ObjectsQuerySource;
use iikiti\CMS\Web\BlockEditor\Query\QueryDefinition;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Regression: a non-empty objectType used to build `o.type = :objectType`, but
 * `type` is the Doctrine discriminator and is not a mapped field, so DQL failed.
 */
final class ObjectsQuerySourceTest extends TestCase
{
	public function testObjectTypeFilterUsesInstanceOfDiscriminator(): void
	{
		$capturedConditions = [];

		$query = $this->createStub(\Doctrine\ORM\Query::class);
		$query->method('getResult')->willReturn([]);

		$queryBuilder = $this->createStub(\Doctrine\ORM\QueryBuilder::class);
		$queryBuilder->method('select')->willReturnSelf();
		$queryBuilder->method('from')->willReturnSelf();
		$queryBuilder->method('leftJoin')->willReturnSelf();
		$queryBuilder->method('setParameter')->willReturnSelf();
		$queryBuilder->method('setMaxResults')->willReturnSelf();
		$queryBuilder->method('addOrderBy')->willReturnSelf();
		$queryBuilder->method('getQuery')->willReturn($query);
		$queryBuilder->method('andWhere')->willReturnCallback(
			static function (string $condition) use (&$capturedConditions, $queryBuilder): \Doctrine\ORM\QueryBuilder {
				$capturedConditions[] = $condition;

				return $queryBuilder;
			}
		);

		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('createQueryBuilder')->willReturn($queryBuilder);

		$cacheItem = $this->createStub(CacheItemInterface::class);
		$cacheItem->method('isHit')->willReturn(false);
		$cache = $this->createStub(CacheItemPoolInterface::class);
		$cache->method('getItem')->willReturn($cacheItem);

		$source = new ObjectsQuerySource($entityManager, $cache);
		$definition = QueryDefinition::fromArray([
			'source' => 'objects',
			'objectType' => 'iikiti\\CMS\\Entity\\Object\\Page',
			'limit' => 5,
		]);

		$source->execute($definition, null);

		self::assertContains('o INSTANCE OF :objectType', $capturedConditions);
		self::assertNotContains('o.type = :objectType', $capturedConditions);
	}

	public function testPropertyJoinsExcludeSecretProperties(): void
	{
		$joins = [];
		$query = $this->createStub(\Doctrine\ORM\Query::class);
		$query->method('getResult')->willReturn([]);
		$queryBuilder = $this->createStub(\Doctrine\ORM\QueryBuilder::class);
		$queryBuilder->method('select')->willReturnSelf();
		$queryBuilder->method('from')->willReturnSelf();
		$queryBuilder->method('setParameter')->willReturnSelf();
		$queryBuilder->method('setMaxResults')->willReturnSelf();
		$queryBuilder->method('addOrderBy')->willReturnSelf();
		$queryBuilder->method('andWhere')->willReturnSelf();
		$queryBuilder->method('leftJoin')->willReturnCallback(static function (string $join, string $alias, mixed $conditionType, string $condition) use (&$joins, $queryBuilder): \Doctrine\ORM\QueryBuilder {
			$joins[] = $condition;

			return $queryBuilder;
		});
		$queryBuilder->method('getQuery')->willReturn($query);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('createQueryBuilder')->willReturn($queryBuilder);
		$cacheItem = $this->createStub(CacheItemInterface::class);
		$cacheItem->method('isHit')->willReturn(false);
		$cache = $this->createStub(CacheItemPoolInterface::class);
		$cache->method('getItem')->willReturn($cacheItem);
		$definition = QueryDefinition::fromArray([
			'source' => 'objects',
			'filters' => [['field' => 'title', 'op' => 'eq', 'value' => 'Visible']],
		]);

		(new ObjectsQuerySource($entityManager, $cache))->execute($definition, null);

		self::assertCount(1, $joins);
		self::assertStringContainsString('titleProp.secret = false', $joins[0]);
	}

	public function testObjectAndPropertyWriteGenerationsInvalidateObjectQueryCacheKeys(): void
	{
		$cacheKeys = [];
		$query = $this->createStub(\Doctrine\ORM\Query::class);
		$query->method('getResult')->willReturn([]);
		$queryBuilder = $this->createStub(\Doctrine\ORM\QueryBuilder::class);
		foreach (['select', 'from', 'leftJoin', 'setParameter', 'setMaxResults', 'addOrderBy', 'andWhere'] as $method) {
			$queryBuilder->method($method)->willReturnSelf();
		}
		$queryBuilder->method('getQuery')->willReturn($query);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('createQueryBuilder')->willReturn($queryBuilder);

		$item = $this->createStub(CacheItemInterface::class);
		$item->method('isHit')->willReturn(false);
		$cache = $this->createStub(CacheItemPoolInterface::class);
		$cache->method('getItem')->willReturnCallback(static function (string $key) use (&$cacheKeys, $item): CacheItemInterface {
			$cacheKeys[] = $key;

			return $item;
		});

		$objectGeneration = 1;
		$propertyGeneration = 1;
		$cacheManager = $this->createStub(DatabaseCacheManager::class);
		$cacheManager->method('getGeneration')->willReturnCallback(static function (string $class) use (&$objectGeneration, &$propertyGeneration): int {
			return DbObject::class === $class ? $objectGeneration : $propertyGeneration;
		});
		$source = new ObjectsQuerySource($entityManager, $cache, $cacheManager);
		$definition = QueryDefinition::fromArray(['source' => 'objects', 'limit' => 5]);

		$source->execute($definition, 3);
		$objectGeneration++;
		$source->execute($definition, 3);
		$propertyGeneration++;
		$source->execute($definition, 3);

		self::assertCount(3, $cacheKeys);
		self::assertCount(3, array_unique($cacheKeys));
	}
}
