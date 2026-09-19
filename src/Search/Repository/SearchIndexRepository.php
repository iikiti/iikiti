<?php

namespace iikiti\CMS\Search\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Search\Entity\SearchIndex;

/**
 * @extends ServiceEntityRepository<SearchIndex>
 */
class SearchIndexRepository extends ServiceEntityRepository
{
	public function __construct(
		ManagerRegistry $registry,
		string $entityClass = SearchIndex::class,
	) {
		parent::__construct($registry, $entityClass);
	}

	/**
	 * Find a search index by slug, preferring a site-specific config and
	 * falling back to the global (siteId = NULL) system config.
	 */
	public function findBySlug(string $slug, int|string|null $siteId = null): ?SearchIndex
	{
		if (null !== $siteId) {
			/** @var SearchIndex|null $specific */
			$specific = $this->findOneBy(['slug' => $slug, 'siteId' => $siteId]);
			if (null !== $specific) {
				return $specific;
			}
		}

		/** @var SearchIndex|null $global */
		$global = $this->findOneBy(['slug' => $slug, 'siteId' => null]);

		return $global;
	}

	/**
	 * Find a search index by id.
	 */
	public function findById(int|string $id): ?SearchIndex
	{
		/** @var SearchIndex|null $result */
		$result = $this->find($id);

		return $result;
	}
}
