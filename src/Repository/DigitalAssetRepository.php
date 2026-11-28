<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository;

use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Object\DigitalAssetFolder;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\DatabaseCacheManager;

/** @extends ObjectRepository<AbstractDigitalAsset> */
class DigitalAssetRepository extends ObjectRepository
{
	/** @var list<string> */
	private const DOCUMENT_MIME_TYPES = [
		'application/pdf',
		'application/msword',
	];

	/** @var list<string> */
	private const DATA_MIME_TYPES = [
		'application/json',
		'application/xml',
		'text/xml',
	];

	public function __construct(ManagerRegistry $registry, SiteRegistry $siteRegistry, DatabaseCacheManager $cacheManager)
	{
		parent::__construct($registry, $siteRegistry, $cacheManager, AbstractDigitalAsset::class);
	}

	/**
	 * @param array<string,mixed> $options
	 */
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
		/** @var list<AbstractDigitalAsset> $results */
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

	/**
	 * Returns filtered and sorted assets manageable by the caller.
	 *
	 * @param list<int|string> $manageableSiteIds
	 *
	 * @return list<AbstractDigitalAsset>
	 */
	public function findManageableFiltered(
		array $manageableSiteIds,
		bool $isGlobalAdmin,
		DigitalAssetFilterCriteria $criteria,
	): array {
		if (!$isGlobalAdmin && [] === $manageableSiteIds) {
			return [];
		}

		$qb = $this->buildManageableFilteredQuery($manageableSiteIds, $isGlobalAdmin, $criteria);
		$this->applySort($qb, $criteria->sort);

		/** @var list<AbstractDigitalAsset> $results */
		$results = $qb->
			setMaxResults(max(1, $criteria->limit))->
			setFirstResult(max(0, $criteria->offset))->
			getQuery()->
			getResult();

		return $results;
	}

	/**
	 * Counts filtered assets manageable by the caller.
	 *
	 * @param list<int|string> $manageableSiteIds
	 */
	public function countManageableFiltered(
		array $manageableSiteIds,
		bool $isGlobalAdmin,
		DigitalAssetFilterCriteria $criteria,
	): int {
		if (!$isGlobalAdmin && [] === $manageableSiteIds) {
			return 0;
		}

		return (int) $this->buildManageableFilteredQuery($manageableSiteIds, $isGlobalAdmin, $criteria)->
			select('COUNT(DISTINCT asset.id)')->
			getQuery()->
			getSingleScalarResult();
	}

	/**
	 * Computes faceted counts for the manageable asset listing by `mimeCategory`,
	 * `visibility`, `processingStatus`, `siteId`, and `licenseStatus` (along with
	 * `expiringCount` and `expiredCount`).
	 *
	 * @param list<int|string> $manageableSiteIds
	 *
	 * @return array{
	 *     mimeCategory: array<string,int>,
	 *     visibility: array<string,int>,
	 *     processingStatus: array<string,int>,
	 *     siteId: array<string,int>,
	 *     licenseStatus: array<string,int>,
	 *     expiringCount: int,
	 *     expiredCount: int
	 * }
	 */
	public function computeFacets(
		array $manageableSiteIds,
		bool $isGlobalAdmin,
		DigitalAssetFilterCriteria $criteria,
	): array {
		$empty = [
			'mimeCategory' => [],
			'visibility' => [],
			'processingStatus' => [],
			'siteId' => [],
			'licenseStatus' => [
				'valid' => 0,
				'expiring_soon' => 0,
				'expired' => 0,
				'missing' => 0,
			],
			'expiringCount' => 0,
			'expiredCount' => 0,
		];
		if (!$isGlobalAdmin && [] === $manageableSiteIds) {
			return $empty;
		}

		$mimeCounts = [];
		$mimeRows = $this->buildManageableFilteredQuery($manageableSiteIds, $isGlobalAdmin, $criteria->withoutFacet('mimeCategory'))->
			select('asset.mimeType AS mimeType, COUNT(DISTINCT asset.id) AS cnt')->
			groupBy('asset.mimeType')->
			getQuery()->
			getArrayResult();
		foreach ($mimeRows as $row) {
			$category = self::classifyMimeCategory((string) ($row['mimeType'] ?? ''));
			$mimeCounts[$category] = ($mimeCounts[$category] ?? 0) + (int) ($row['cnt'] ?? 0);
		}
		ksort($mimeCounts);

		$visibilityCounts = [];
		$visibilityRows = $this->buildManageableFilteredQuery($manageableSiteIds, $isGlobalAdmin, $criteria->withoutFacet('visibility'))->
			select('asset.visibility AS val, COUNT(DISTINCT asset.id) AS cnt')->
			groupBy('asset.visibility')->
			getQuery()->
			getArrayResult();
		foreach ($visibilityRows as $row) {
			$val = (string) ($row['val'] ?? '');
			if ('' !== $val) {
				$visibilityCounts[$val] = (int) ($row['cnt'] ?? 0);
			}
		}
		ksort($visibilityCounts);

		$statusCounts = [];
		$statusRows = $this->buildManageableFilteredQuery($manageableSiteIds, $isGlobalAdmin, $criteria->withoutFacet('processingStatus'))->
			select('asset.processingStatus AS val, COUNT(DISTINCT asset.id) AS cnt')->
			groupBy('asset.processingStatus')->
			getQuery()->
			getArrayResult();
		foreach ($statusRows as $row) {
			$val = (string) ($row['val'] ?? '');
			if ('' !== $val) {
				$statusCounts[$val] = (int) ($row['cnt'] ?? 0);
			}
		}
		ksort($statusCounts);

		$siteCounts = [];
		$siteRows = $this->buildManageableFilteredQuery($manageableSiteIds, $isGlobalAdmin, $criteria->withoutFacet('siteId'))->
			innerJoin('asset.accessSites', 'facetSite')->
			select('facetSite.id AS siteId, COUNT(DISTINCT asset.id) AS cnt')->
			groupBy('facetSite.id')->
			getQuery()->
			getArrayResult();
		foreach ($siteRows as $row) {
			$id = (string) ($row['siteId'] ?? '');
			if ('' !== $id) {
				$siteCounts[$id] = (int) ($row['cnt'] ?? 0);
			}
		}
		ksort($siteCounts);

		$licenseBaseCriteria = $criteria->withoutFacet('licenseStatus');
		$licenseCounts = [];
		foreach (['valid', 'expiring_soon', 'expired', 'missing'] as $bucket) {
			$qb = $this->buildManageableFilteredQuery($manageableSiteIds, $isGlobalAdmin, $licenseBaseCriteria);
			$this->applyLicenseStatusFilter($qb, $bucket);
			$licenseCounts[$bucket] = (int) $qb->select('COUNT(DISTINCT asset.id)')->getQuery()->getSingleScalarResult();
		}

		return [
			'mimeCategory' => $mimeCounts,
			'visibility' => $visibilityCounts,
			'processingStatus' => $statusCounts,
			'siteId' => $siteCounts,
			'licenseStatus' => $licenseCounts,
			'expiringCount' => $licenseCounts['expiring_soon'],
			'expiredCount' => $licenseCounts['expired'],
		];
	}

	public static function classifyMimeCategory(string $mimeType): string
	{
		$normalized = strtolower(trim($mimeType));
		if (str_starts_with($normalized, 'image/')) {
			return 'image';
		}
		if (str_starts_with($normalized, 'video/')) {
			return 'video';
		}
		if (str_starts_with($normalized, 'audio/')) {
			return 'audio';
		}
		if (in_array($normalized, self::DATA_MIME_TYPES, true)) {
			return 'data';
		}
		if (str_starts_with($normalized, 'text/')) {
			return 'text';
		}
		if (in_array($normalized, self::DOCUMENT_MIME_TYPES, true) || str_starts_with($normalized, 'application/vnd.')) {
			return 'document';
		}

		return 'other';
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
	 * search, MIME, folder, collection, tag, and sort filters for the editor media browser.
	 *
	 * @param list<int|string> $tagIds
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
		int|string|null $folderId = null,
		bool $includeSubfolders = false,
		int|string|null $collectionId = null,
		array $tagIds = [],
		string $sort = DigitalAssetFilterCriteria::SORT_NEWEST,
		bool $unfiledOnly = false,
	): array {
		$criteria = new DigitalAssetFilterCriteria(
			query: $query,
			mimeCategory: $mimeCategory,
			mimeType: $mimeType,
			folderId: $unfiledOnly ? null : $folderId,
			unfiledOnly: $unfiledOnly,
			includeSubfolders: $includeSubfolders,
			collectionId: $collectionId,
			tagIds: $tagIds,
			sort: $sort,
			limit: max(1, $limit),
			offset: max(0, $offset),
		);

		return $this->findAccessibleToSiteByCriteria($site, $criteria);
	}

	/**
	 * @param list<int|string> $tagIds
	 */
	public function countAccessibleToSiteFiltered(
		Site $site,
		?string $query = null,
		?string $mimeCategory = null,
		?string $mimeType = null,
		int|string|null $folderId = null,
		bool $includeSubfolders = false,
		int|string|null $collectionId = null,
		array $tagIds = [],
		bool $unfiledOnly = false,
	): int {
		$criteria = new DigitalAssetFilterCriteria(
			query: $query,
			mimeCategory: $mimeCategory,
			mimeType: $mimeType,
			folderId: $unfiledOnly ? null : $folderId,
			unfiledOnly: $unfiledOnly,
			includeSubfolders: $includeSubfolders,
			collectionId: $collectionId,
			tagIds: $tagIds,
		);

		return $this->countAccessibleToSiteByCriteria($site, $criteria);
	}

	/**
	 * @return list<AbstractDigitalAsset>
	 */
	public function findAccessibleToSiteByCriteria(Site $site, DigitalAssetFilterCriteria $criteria): array
	{
		$qb = $this->buildAccessibleToSiteQuery($site, $criteria);
		$this->applySort($qb, $criteria->sort);

		/** @var list<AbstractDigitalAsset> $results */
		$results = $qb->
			setMaxResults(max(1, $criteria->limit))->
			setFirstResult(max(0, $criteria->offset))->
			getQuery()->
			getResult();

		return $results;
	}

	public function countAccessibleToSiteByCriteria(Site $site, DigitalAssetFilterCriteria $criteria): int
	{
		return (int) $this->buildAccessibleToSiteQuery($site, $criteria)->
			select('COUNT(DISTINCT asset.id)')->
			getQuery()->
			getSingleScalarResult();
	}

	/** @param list<int|string> $manageableSiteIds */
	private function buildManageableFilteredQuery(
		array $manageableSiteIds,
		bool $isGlobalAdmin,
		DigitalAssetFilterCriteria $criteria,
	): QueryBuilder {
		$qb = $this->createQueryBuilder('asset');

		if (!$isGlobalAdmin) {
			$qb->andWhere(
				'asset.id IN (SELECT m_asset.id FROM '.AbstractDigitalAsset::class.' m_asset INNER JOIN m_asset.accessSites m_site LEFT JOIN m_asset.accessSites m_all WHERE m_site.id IN (:manageableSiteIds) GROUP BY m_asset.id HAVING COUNT(DISTINCT m_all.id) = COUNT(DISTINCT m_site.id))'
			)->setParameter('manageableSiteIds', $manageableSiteIds);
		}

		$this->applyFilterCriteria($qb, $criteria);

		return $qb;
	}

	private function buildAccessibleToSiteQuery(Site $site, DigitalAssetFilterCriteria $criteria): QueryBuilder
	{
		$qb = $this->createQueryBuilder('asset')->
			andWhere(
				'asset.id IN (SELECT s_asset.id FROM '.AbstractDigitalAsset::class.' s_asset INNER JOIN s_asset.accessSites s_site WHERE s_site.id = :accessibleSiteId)'
			)->
			setParameter('accessibleSiteId', $site->getId());

		$this->applyFilterCriteria($qb, $criteria);

		return $qb;
	}

	private function applyFilterCriteria(QueryBuilder $qb, DigitalAssetFilterCriteria $criteria): void
	{
		$trimmedQuery = null !== $criteria->query ? trim($criteria->query) : '';
		if ('' !== $trimmedQuery) {
			$escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($trimmedQuery));
			$qb->andWhere(
				'(LOWER(asset.title) LIKE :searchQuery OR (asset.originalName IS NOT NULL AND LOWER(asset.originalName) LIKE :searchQuery) OR asset.id IN (SELECT qt_asset.id FROM '.AbstractDigitalAsset::class.' qt_asset INNER JOIN qt_asset.tags qt_tag WHERE LOWER(qt_tag.name) LIKE :searchQuery OR LOWER(qt_tag.slug) LIKE :searchQuery))'
			)->setParameter('searchQuery', '%'.$escaped.'%');
		}

		$trimmedMimeType = null !== $criteria->mimeType ? strtolower(trim($criteria->mimeType)) : '';
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

		$trimmedCategory = null !== $criteria->mimeCategory ? strtolower(trim($criteria->mimeCategory)) : '';
		if ('' !== $trimmedCategory) {
			match ($trimmedCategory) {
				'image' => $qb->andWhere('LOWER(asset.mimeType) LIKE :catPrefix')->setParameter('catPrefix', 'image/%'),
				'video' => $qb->andWhere('LOWER(asset.mimeType) LIKE :catPrefix')->setParameter('catPrefix', 'video/%'),
				'audio' => $qb->andWhere('LOWER(asset.mimeType) LIKE :catPrefix')->setParameter('catPrefix', 'audio/%'),
				'text' => $qb->andWhere('LOWER(asset.mimeType) LIKE :catPrefix AND LOWER(asset.mimeType) NOT IN (:catExcludeData)')->
					setParameter('catPrefix', 'text/%')->
					setParameter('catExcludeData', self::DATA_MIME_TYPES),
				'document' => $qb->andWhere('(LOWER(asset.mimeType) IN (:docMimes) OR LOWER(asset.mimeType) LIKE :docPrefix)')->
					setParameter('docMimes', self::DOCUMENT_MIME_TYPES)->
					setParameter('docPrefix', 'application/vnd.%'),
				'data' => $qb->andWhere('LOWER(asset.mimeType) IN (:dataMimes)')->
					setParameter('dataMimes', self::DATA_MIME_TYPES),
				'other' => $qb->andWhere(
					'NOT (LOWER(asset.mimeType) LIKE :otherImg OR LOWER(asset.mimeType) LIKE :otherVid OR LOWER(asset.mimeType) LIKE :otherAud OR LOWER(asset.mimeType) LIKE :otherTxt OR LOWER(asset.mimeType) IN (:otherDocMimes) OR LOWER(asset.mimeType) LIKE :otherDocPrefix OR LOWER(asset.mimeType) IN (:otherDataMimes))'
				)->
					setParameter('otherImg', 'image/%')->
					setParameter('otherVid', 'video/%')->
					setParameter('otherAud', 'audio/%')->
					setParameter('otherTxt', 'text/%')->
					setParameter('otherDocMimes', self::DOCUMENT_MIME_TYPES)->
					setParameter('otherDocPrefix', 'application/vnd.%')->
					setParameter('otherDataMimes', self::DATA_MIME_TYPES),
				default => null,
			};
		}

		if (null !== $criteria->siteId && '' !== (string) $criteria->siteId) {
			$qb->andWhere(
				'asset.id IN (SELECT fs_asset.id FROM '.AbstractDigitalAsset::class.' fs_asset INNER JOIN fs_asset.accessSites fs_site WHERE fs_site.id = :filterSiteId)'
			)->setParameter('filterSiteId', $criteria->siteId);
		}

		if (null !== $criteria->visibility && '' !== trim($criteria->visibility)) {
			$qb->andWhere('LOWER(asset.visibility) = :filterVisibility')->
				setParameter('filterVisibility', strtolower(trim($criteria->visibility)));
		}

		if (null !== $criteria->processingStatus && '' !== trim($criteria->processingStatus)) {
			$qb->andWhere('LOWER(asset.processingStatus) = :filterProcessingStatus')->
				setParameter('filterProcessingStatus', strtolower(trim($criteria->processingStatus)));
		}

		if ($criteria->unfiledOnly) {
			$qb->andWhere('asset.folder IS NULL');
		} elseif (null !== $criteria->folderId && '' !== (string) $criteria->folderId) {
			if ($criteria->includeSubfolders) {
				$folder = $this->getEntityManager()->find(DigitalAssetFolder::class, $criteria->folderId);
				if ($folder instanceof DigitalAssetFolder) {
					$escapedPath = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], rtrim($folder->getPath(), '/'));
					$qb->innerJoin('asset.folder', 'filterFolder')->
						andWhere('(filterFolder.id = :filterFolderId OR filterFolder.path LIKE :filterFolderPathPrefix)')->
						setParameter('filterFolderId', $criteria->folderId)->
						setParameter('filterFolderPathPrefix', $escapedPath.'/%');
				} else {
					$qb->innerJoin('asset.folder', 'filterFolder')->
						andWhere('filterFolder.id = :filterFolderId')->
						setParameter('filterFolderId', $criteria->folderId);
				}
			} else {
				$qb->innerJoin('asset.folder', 'filterFolder')->
					andWhere('filterFolder.id = :filterFolderId')->
					setParameter('filterFolderId', $criteria->folderId);
			}
		}

		if (null !== $criteria->collectionId && '' !== (string) $criteria->collectionId) {
			$qb->andWhere(
				'asset.id IN (SELECT fc_asset.id FROM '.AbstractDigitalAsset::class.' fc_asset INNER JOIN fc_asset.collections fc_col WHERE fc_col.id = :filterCollectionId)'
			)->setParameter('filterCollectionId', $criteria->collectionId);
		}

		if ([] !== $criteria->tagIds) {
			foreach (array_values($criteria->tagIds) as $index => $tagId) {
				$aliasAsset = 'ft'.$index.'_asset';
				$aliasTag = 'ft'.$index.'_tag';
				$param = 'filterTagId'.$index;
				$qb->andWhere(sprintf(
					'asset.id IN (SELECT %s.id FROM %s %s INNER JOIN %s.tags %s WHERE %s.id = :%s)',
					$aliasAsset,
					AbstractDigitalAsset::class,
					$aliasAsset,
					$aliasAsset,
					$aliasTag,
					$aliasTag,
					$param,
				))->setParameter($param, $tagId);
			}
		}

		if (null !== $criteria->licenseStatus && '' !== trim($criteria->licenseStatus)) {
			$this->applyLicenseStatusFilter($qb, strtolower(trim($criteria->licenseStatus)));
		}
	}

	/**
	 * Finds assets matching the given SHA-256 checksum strictly within the
	 * caller's accessible/manageable sites (preventing cross-tenant existence oracles).
	 *
	 * @param list<int|string> $siteIds
	 *
	 * @return list<AbstractDigitalAsset>
	 */
	public function findByChecksumForSites(string $checksum, array $siteIds, bool $isGlobalAdmin): array
	{
		$normalized = strtolower(trim($checksum));
		if (1 !== preg_match('/^[a-f0-9]{64}$/', $normalized)) {
			return [];
		}

		$normalizedSiteIds = array_values(array_unique(array_filter(
			array_map(static fn (int|string $id): string => trim((string) $id), $siteIds),
			static fn (string $id): bool => '' !== $id && ctype_digit($id),
		)));

		if (!$isGlobalAdmin && [] === $normalizedSiteIds) {
			return [];
		}

		$qb = $this->createQueryBuilder('asset')->
			andWhere('asset.checksum = :checksum')->
			setParameter('checksum', $normalized)->
			orderBy('asset.id', 'DESC');

		if ([] !== $normalizedSiteIds) {
			$qb->andWhere(
				'asset.id IN (SELECT dup_asset.id FROM '.AbstractDigitalAsset::class.' dup_asset INNER JOIN dup_asset.accessSites dup_site WHERE dup_site.id IN (:dupSiteIds))'
			)->setParameter('dupSiteIds', $normalizedSiteIds);
		}

		/** @var list<AbstractDigitalAsset> $results */
		$results = $qb->getQuery()->getResult();

		return $results;
	}

	/**
	 * Finds assets whose license expires within ($now, $until] and have not yet
	 * had an expiring-soon notification recorded.
	 *
	 * @return list<AbstractDigitalAsset>
	 */
	public function findExpiringSoon(\DateTimeImmutable $now, \DateTimeImmutable $until): array
	{
		/** @var list<AbstractDigitalAsset> $results */
		$results = $this->createQueryBuilder('asset')->
			andWhere('asset.expiresAt IS NOT NULL')->
			andWhere('asset.expiresAt > :now')->
			andWhere('asset.expiresAt <= :until')->
			andWhere('asset.expiryNotifiedAt IS NULL')->
			setParameter('now', $now)->
			setParameter('until', $until)->
			orderBy('asset.expiresAt', 'ASC')->
			addOrderBy('asset.id', 'ASC')->
			getQuery()->
			getResult();

		return $results;
	}

	/**
	 * Finds assets whose license has expired at or before `$now` and have not yet
	 * been handled by the expiration scheduler.
	 *
	 * @return list<AbstractDigitalAsset>
	 */
	public function findExpiredUnhandled(\DateTimeImmutable $now): array
	{
		/** @var list<AbstractDigitalAsset> $results */
		$results = $this->createQueryBuilder('asset')->
			andWhere('asset.expiresAt IS NOT NULL')->
			andWhere('asset.expiresAt <= :now')->
			andWhere('asset.expiredHandledAt IS NULL')->
			setParameter('now', $now)->
			orderBy('asset.expiresAt', 'ASC')->
			addOrderBy('asset.id', 'ASC')->
			getQuery()->
			getResult();

		return $results;
	}

	private function applyLicenseStatusFilter(QueryBuilder $qb, string $licenseStatus): void
	{
		$now = new \DateTimeImmutable();
		$soon = $now->modify('+30 days');

		match ($licenseStatus) {
			'missing' => $qb->andWhere(
				'asset.licenseType IS NULL AND asset.licenseHolder IS NULL AND asset.licenseUrl IS NULL AND asset.licenseNotes IS NULL AND asset.expiresAt IS NULL'
			),
			'expired' => $qb->andWhere('asset.expiresAt IS NOT NULL AND asset.expiresAt <= :licenseNow')->
				setParameter('licenseNow', $now),
			'expiring_soon', 'expiring' => $qb->andWhere(
				'asset.expiresAt IS NOT NULL AND asset.expiresAt > :licenseNow AND asset.expiresAt <= :licenseSoon'
			)->
				setParameter('licenseNow', $now)->
				setParameter('licenseSoon', $soon),
			'valid', 'licensed' => $qb->andWhere(
				'(asset.licenseType IS NOT NULL OR asset.licenseHolder IS NOT NULL OR asset.licenseUrl IS NOT NULL OR asset.licenseNotes IS NOT NULL OR asset.expiresAt IS NOT NULL) AND (asset.expiresAt IS NULL OR asset.expiresAt > :licenseNow)'
			)->setParameter('licenseNow', $now),
			default => null,
		};
	}

	private function applySort(QueryBuilder $qb, string $sort): void
	{
		match ($sort) {
			DigitalAssetFilterCriteria::SORT_OLDEST => $qb->orderBy('asset.created_date', 'ASC')->addOrderBy('asset.id', 'ASC'),
			DigitalAssetFilterCriteria::SORT_TITLE_ASC => $qb->orderBy('asset.title', 'ASC')->addOrderBy('asset.id', 'DESC'),
			DigitalAssetFilterCriteria::SORT_TITLE_DESC => $qb->orderBy('asset.title', 'DESC')->addOrderBy('asset.id', 'DESC'),
			DigitalAssetFilterCriteria::SORT_SIZE_DESC => $qb->orderBy('asset.size', 'DESC')->addOrderBy('asset.id', 'DESC'),
			DigitalAssetFilterCriteria::SORT_SIZE_ASC => $qb->orderBy('asset.size', 'ASC')->addOrderBy('asset.id', 'DESC'),
			default => $qb->orderBy('asset.created_date', 'DESC')->addOrderBy('asset.id', 'DESC'),
		};
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
