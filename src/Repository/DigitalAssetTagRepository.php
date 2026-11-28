<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository;

use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\DigitalAssetTag;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\DatabaseCacheManager;

/** @extends ObjectRepository<DigitalAssetTag> */
class DigitalAssetTagRepository extends ObjectRepository
{
	public function __construct(ManagerRegistry $registry, SiteRegistry $siteRegistry, DatabaseCacheManager $cacheManager)
	{
		parent::__construct($registry, $siteRegistry, $cacheManager, DigitalAssetTag::class);
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
	 * Case-insensitive lookup by `(namespace, slug)`.
	 */
	public function findOneByNamespaceAndSlug(string $namespace, string $slug): ?DigitalAssetTag
	{
		$normalizedNs = DigitalAssetTag::normalizeNamespace($namespace);
		$normalizedSlug = DigitalAssetTag::slugify($slug);

		$result = $this->createQueryBuilder('tag')->
			andWhere('LOWER(tag.namespace) = :namespace')->
			andWhere('LOWER(tag.slug) = :slug')->
			setParameter('namespace', $normalizedNs)->
			setParameter('slug', $normalizedSlug)->
			setMaxResults(1)->
			getQuery()->
			getOneOrNullResult();

		return $result instanceof DigitalAssetTag ? $result : null;
	}

	/**
	 * @return list<DigitalAssetTag>
	 */
	public function findAllOrdered(?string $namespace = null, ?string $query = null): array
	{
		$qb = $this->createQueryBuilder('tag');

		if (null !== $namespace && '' !== trim($namespace)) {
			$qb->andWhere('LOWER(tag.namespace) = :namespace')->
				setParameter('namespace', DigitalAssetTag::normalizeNamespace($namespace));
		}

		if (null !== $query && '' !== trim($query)) {
			$escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($query)));
			$qb->andWhere('LOWER(tag.name) LIKE :searchQuery OR LOWER(tag.slug) LIKE :searchQuery')->
				setParameter('searchQuery', '%'.$escaped.'%');
		}

		/** @var list<DigitalAssetTag> $results */
		$results = $qb->
			orderBy('tag.namespace', 'ASC')->
			addOrderBy('tag.name', 'ASC')->
			addOrderBy('tag.id', 'ASC')->
			getQuery()->
			getResult();

		return $results;
	}

	/**
	 * @param list<int|string> $ids
	 *
	 * @return list<DigitalAssetTag>
	 */
	public function findByIds(array $ids): array
	{
		$normalized = array_values(array_unique(array_map('strval', $ids)));
		if ([] === $normalized) {
			return [];
		}

		/** @var list<DigitalAssetTag> $results */
		$results = $this->createQueryBuilder('tag')->
			andWhere('tag.id IN (:ids)')->
			setParameter('ids', $normalized)->
			orderBy('tag.name', 'ASC')->
			addOrderBy('tag.id', 'ASC')->
			getQuery()->
			getResult();

		return $results;
	}
}
