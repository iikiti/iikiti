<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository;

use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\DigitalAssetFolder;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\DatabaseCacheManager;

/** @extends ObjectRepository<DigitalAssetFolder> */
class DigitalAssetFolderRepository extends ObjectRepository
{
	public function __construct(ManagerRegistry $registry, SiteRegistry $siteRegistry, DatabaseCacheManager $cacheManager)
	{
		parent::__construct($registry, $siteRegistry, $cacheManager, DigitalAssetFolder::class);
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
	 * @return list<DigitalAssetFolder>
	 */
	public function findAllOrdered(): array
	{
		/** @var list<DigitalAssetFolder> $results */
		$results = $this->createQueryBuilder('folder')->
			orderBy('folder.path', 'ASC')->
			addOrderBy('folder.id', 'ASC')->
			getQuery()->
			getResult();

		return $results;
	}

	/**
	 * Returns folders whose explicit site grants are all within `$siteIds`.
	 *
	 * @param list<int|string> $siteIds
	 *
	 * @return list<DigitalAssetFolder>
	 */
	public function findManageableBySites(array $siteIds): array
	{
		if ([] === $siteIds) {
			return [];
		}

		/** @var list<DigitalAssetFolder> $results */
		$results = $this->createQueryBuilder('folder')->
			andWhere(
				'folder.id IN (SELECT mf.id FROM '.DigitalAssetFolder::class.' mf INNER JOIN mf.accessSites ms LEFT JOIN mf.accessSites ma WHERE ms.id IN (:siteIds) GROUP BY mf.id HAVING COUNT(DISTINCT ma.id) = COUNT(DISTINCT ms.id))'
			)->
			setParameter('siteIds', $siteIds)->
			orderBy('folder.path', 'ASC')->
			addOrderBy('folder.id', 'ASC')->
			getQuery()->
			getResult();

		return $results;
	}

	/**
	 * Returns folders accessible to the given site (global folders with no site
	 * grants, or folders explicitly granted to `$site`).
	 *
	 * @return list<DigitalAssetFolder>
	 */
	public function findAccessibleToSite(Site $site): array
	{
		/** @var list<DigitalAssetFolder> $results */
		$results = $this->createQueryBuilder('folder')->
			leftJoin('folder.accessSites', 'grantedSite')->
			andWhere('grantedSite.id = :siteId OR SIZE(folder.accessSites) = :zeroCount')->
			setParameter('siteId', $site->getId())->
			setParameter('zeroCount', 0)->
			orderBy('folder.path', 'ASC')->
			addOrderBy('folder.id', 'ASC')->
			getQuery()->
			getResult();

		return $results;
	}

	/**
	 * @return list<DigitalAssetFolder>
	 */
	public function findChildren(DigitalAssetFolder $parent): array
	{
		/** @var list<DigitalAssetFolder> $results */
		$results = $this->createQueryBuilder('folder')->
			andWhere('folder.parent = :parent')->
			setParameter('parent', $parent)->
			orderBy('folder.path', 'ASC')->
			addOrderBy('folder.id', 'ASC')->
			getQuery()->
			getResult();

		return $results;
	}

	/**
	 * Finds all descendant folders strictly below `$pathPrefix` (matching `$pathPrefix.'/%'`).
	 *
	 * @return list<DigitalAssetFolder>
	 */
	public function findDescendantsByPathPrefix(string $pathPrefix): array
	{
		$normalized = rtrim($pathPrefix, '/');
		$escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalized);

		/** @var list<DigitalAssetFolder> $results */
		$results = $this->createQueryBuilder('folder')->
			andWhere('folder.path LIKE :pathPrefix')->
			setParameter('pathPrefix', $escaped.'/%')->
			orderBy('folder.path', 'ASC')->
			addOrderBy('folder.id', 'ASC')->
			getQuery()->
			getResult();

		return $results;
	}

	public function findByParentAndSlug(?DigitalAssetFolder $parent, string $slug): ?DigitalAssetFolder
	{
		$qb = $this->createQueryBuilder('folder')->
			andWhere('LOWER(folder.slug) = :slug')->
			setParameter('slug', strtolower(trim($slug)));

		if (null === $parent) {
			$qb->andWhere('folder.parent IS NULL');
		} else {
			$qb->andWhere('folder.parent = :parent')->setParameter('parent', $parent);
		}

		$result = $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();

		return $result instanceof DigitalAssetFolder ? $result : null;
	}
}
