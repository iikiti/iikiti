<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository;

use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\DigitalAssetCollection;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\DatabaseCacheManager;

/** @extends ObjectRepository<DigitalAssetCollection> */
class DigitalAssetCollectionRepository extends ObjectRepository
{
	public function __construct(ManagerRegistry $registry, SiteRegistry $siteRegistry, DatabaseCacheManager $cacheManager)
	{
		parent::__construct($registry, $siteRegistry, $cacheManager, DigitalAssetCollection::class);
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function createQueryBuilder($alias, $indexBy = null, array $options = []): QueryBuilder
	{
		$options['filterBySite'] ??= false;

		return parent::createQueryBuilder($alias, $indexBy, $options);
	}

	/**
	 * @return list<DigitalAssetCollection>
	 */
	public function findAllOrdered(): array
	{
		/** @var list<DigitalAssetCollection> $results */
		$results = $this->createQueryBuilder('collection')->
			orderBy('collection.name', 'ASC')->
			addOrderBy('collection.id', 'ASC')->
			getQuery()->
			getResult();

		return $results;
	}

	public function findBySlug(string $slug): ?DigitalAssetCollection
	{
		$result = $this->createQueryBuilder('collection')->
			andWhere('LOWER(collection.slug) = :slug')->
			setParameter('slug', strtolower(trim($slug)))->
			setMaxResults(1)->
			getQuery()->
			getOneOrNullResult();

		return $result instanceof DigitalAssetCollection ? $result : null;
	}

	/**
	 * Returns collections that contain at least one asset granted to `$site`.
	 *
	 * @return list<DigitalAssetCollection>
	 */
	public function findAccessibleToSite(Site $site): array
	{
		/** @var list<DigitalAssetCollection> $results */
		$results = $this->createQueryBuilder('collection')->
			innerJoin('collection.assets', 'asset')->
			innerJoin('asset.accessSites', 'grantedSite')->
			andWhere('grantedSite.id = :siteId')->
			setParameter('siteId', $site->getId())->
			distinct()->
			orderBy('collection.name', 'ASC')->
			addOrderBy('collection.id', 'ASC')->
			getQuery()->
			getResult();

		return $results;
	}
}
