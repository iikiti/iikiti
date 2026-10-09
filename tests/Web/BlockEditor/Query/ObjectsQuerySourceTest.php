<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Query;

use Doctrine\ORM\EntityManagerInterface;
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
}
