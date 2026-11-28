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

	/**
	 * Batch-loads assets granted to the given site in a single query.
	 *
	 * @param list<int|string> $ids
	 *
	 * @return list<AbstractDigitalAsset>
	 */
	public function findAccessibleToSiteByIds(array $ids, Site $site): array
	{
		if ([] === $ids) {
			return [];
		}

		/** @var list<AbstractDigitalAsset> $results */
		$results = $this->createQueryBuilder('asset')->
			innerJoin('asset.accessSites', 'grantedSite')->
			andWhere('asset.id IN (:ids)')->
			andWhere('grantedSite.id = :siteId')->
			setParameter('ids', array_values(array_unique($ids)))->
			setParameter('siteId', $site->getId())->
			getQuery()->
			getResult();

		return $results;
	}

	/**
	 * Paginated listing of digital assets granted to a single site, with optional
	 * search, MIME category, and MIME type filters for the editor media browser.
	 *
	 * @return list<AbstractDigitalAsset>
	 */
	public function findAccessibleToSiteFiltered(
		Site $site,
		?string $query = null,
		?string $mimeCategory = null,
		?string $mimeType = null,
		int $limit = 24,
		int $offset = 0,
	): array {
		/** @var list<AbstractDigitalAsset> $results */
		$results = $this->accessibleToSiteFilteredQuery($site, $query, $mimeCategory, $mimeType)->
			orderBy('asset.id', 'DESC')->
			setMaxResults(max(1, $limit))->
			setFirstResult(max(0, $offset))->
			getQuery()->
			getResult();

		return $results;
	}

	public function countAccessibleToSiteFiltered(
		Site $site,
		?string $query = null,
		?string $mimeCategory = null,
		?string $mimeType = null,
	): int {
		return (int) $this->accessibleToSiteFilteredQuery($site, $query, $mimeCategory, $mimeType)->
			select('COUNT(DISTINCT asset.id)')->
			getQuery()->
			getSingleScalarResult();
	}

	private function accessibleToSiteFilteredQuery(
		Site $site,
		?string $query,
		?string $mimeCategory,
		?string $mimeType,
	): QueryBuilder {
		$qb = $this->createQueryBuilder('asset')->
			innerJoin('asset.accessSites', 'grantedSite')->
			andWhere('grantedSite.id = :siteId')->
			setParameter('siteId', $site->getId());

		$trimmedQuery = null !== $query ? trim($query) : '';
		if ('' !== $trimmedQuery) {
			$escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($trimmedQuery));
			$qb->andWhere('LOWER(asset.title) LIKE :searchQuery OR LOWER(COALESCE(asset.originalName, \'\')) LIKE :searchQuery')->
				setParameter('searchQuery', '%'.$escaped.'%');
		}

		$trimmedMimeType = null !== $mimeType ? strtolower(trim($mimeType)) : '';
		if ('' !== $trimmedMimeType) {
			if (str_ends_with($trimmedMimeType, '/*')) {
				$prefix = substr($trimmedMimeType, 0, -1);
				$qb->andWhere('LOWER(asset.mimeType) LIKE :mimePrefix')->
					setParameter('mimePrefix', $prefix.'%');
			} else {
				$qb->andWhere('LOWER(asset.mimeType) = :mimeType')->
					setParameter('mimeType', $trimmedMimeType);
			}
		}

		$trimmedCategory = null !== $mimeCategory ? strtolower(trim($mimeCategory)) : '';
		if ('' !== $trimmedCategory) {
			match ($trimmedCategory) {
				'image' => $qb->andWhere('LOWER(asset.mimeType) LIKE :catPrefix')->setParameter('catPrefix', 'image/%'),
				'video' => $qb->andWhere('LOWER(asset.mimeType) LIKE :catPrefix')->setParameter('catPrefix', 'video/%'),
				'audio' => $qb->andWhere('LOWER(asset.mimeType) LIKE :catPrefix')->setParameter('catPrefix', 'audio/%'),
				'text' => $qb->andWhere('LOWER(asset.mimeType) LIKE :catPrefix')->setParameter('catPrefix', 'text/%'),
				'document' => $qb->andWhere('LOWER(asset.mimeType) IN (:docMimes) OR LOWER(asset.mimeType) LIKE :docPrefix')->
					setParameter('docMimes', ['application/pdf', 'application/msword'])->
					setParameter('docPrefix', 'application/vnd.%'),
				'data' => $qb->andWhere('LOWER(asset.mimeType) IN (:dataMimes)')->
					setParameter('dataMimes', ['application/json', 'application/xml', 'text/xml']),
				default => null,
			};
		}

		return $qb;
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
