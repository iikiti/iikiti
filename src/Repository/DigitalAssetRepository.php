<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository;

use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\DatabaseCacheManager;

/** @extends ObjectRepository<AbstractDigitalAsset> */
class DigitalAssetRepository extends ObjectRepository
{
	public function __construct(ManagerRegistry $registry, SiteRegistry $siteRegistry, DatabaseCacheManager $cacheManager)
	{
		parent::__construct($registry, $siteRegistry, $cacheManager, AbstractDigitalAsset::class);
	}

	public function createQueryBuilder($alias, $indexBy = null, array $options = []): QueryBuilder
	{
		$options['filterBySite'] ??= false;

		return parent::createQueryBuilder($alias, $indexBy, $options);
	}

	/** @param list<int|string> $siteIds
	 * @return list<AbstractDigitalAsset>
	 */
	public function findManageableBySites(array $siteIds, int $limit, int $offset): array
	{
		if ([] === $siteIds) {
			return [];
		}
		$results = $this->manageableQuery($siteIds)->
			orderBy('asset.id', 'DESC')->
			setMaxResults($limit)->
			setFirstResult($offset)->
			getQuery()->
			getResult();

		return $results;
	}

	/** @param list<int|string> $siteIds */
	public function countManageableBySites(array $siteIds): int
	{
		if ([] === $siteIds) {
			return 0;
		}

		return count($this->manageableQuery($siteIds)->select('asset.id')->getQuery()->getScalarResult());
	}

	public function findByPublicToken(string $publicToken): ?AbstractDigitalAsset
	{
		$result = $this->createQueryBuilder('asset')->
			andWhere('asset.publicToken = :token')->
			setParameter('token', $publicToken)->
			getQuery()->
			getOneOrNullResult();

		return $result instanceof AbstractDigitalAsset ? $result : null;
	}

	public function findAccessibleToSite(int|string $id, Site $site): ?AbstractDigitalAsset
	{
		$result = $this->createQueryBuilder('asset')->
			innerJoin('asset.accessSites', 'grantedSite')->
			andWhere('asset.id = :id')->
			andWhere('grantedSite.id = :siteId')->
			setParameter('id', $id)->
			setParameter('siteId', $site->getId())->
			getQuery()->
			getOneOrNullResult();

		return $result instanceof AbstractDigitalAsset ? $result : null;
	}

	public function findPublicTokenForSite(string $publicToken, Site $site): ?AbstractDigitalAsset
	{
		$result = $this->createQueryBuilder('asset')->
			innerJoin('asset.accessSites', 'grantedSite')->
			andWhere('asset.publicToken = :token')->
			andWhere('grantedSite.id = :siteId')->
			setParameter('token', $publicToken)->
			setParameter('siteId', $site->getId())->
			getQuery()->
			getOneOrNullResult();

		return $result instanceof AbstractDigitalAsset ? $result : null;
	}

	/** @param list<int|string> $siteIds */
	private function manageableQuery(array $siteIds): QueryBuilder
	{
		return $this->createQueryBuilder('asset')->
			innerJoin('asset.accessSites', 'manageableSite')->
			leftJoin('asset.accessSites', 'allSite')->
			andWhere('manageableSite.id IN (:siteIds)')->
			groupBy('asset.id')->
			having('COUNT(DISTINCT allSite.id) = COUNT(DISTINCT manageableSite.id)')->
			setParameter('siteIds', $siteIds);
	}
}
