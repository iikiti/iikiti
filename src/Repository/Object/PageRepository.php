<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Service\DatabaseCacheManager;

/**
 * Repository for page entities.
 *
 * @template-extends ObjectRepository<Page>
 */
class PageRepository extends ObjectRepository
{
	public function __construct(
		ManagerRegistry $registry,
		SiteRegistry $siteRegistry,
		DatabaseCacheManager $cacheManager,
		string $entityClass = Page::class
	) {
		parent::__construct($registry, $siteRegistry, $cacheManager, $entityClass);
	}

	/**
	 * Fetch a keyset-paginated batch of page sitemap metadata for a site without
	 * hydrating ORM entities or expanding Doctrine's UnitOfWork.
	 *
	 * @return list<array{id:int,slug:?string,settings:array<string,mixed>}>
	 */
	public function findSitemapBatchForSite(int $siteId, int $afterId, int $limit): array
	{
		if ($siteId < 1 || $limit < 1) {
			return [];
		}

		$connection = $this->getEntityManager()->getConnection();
		$rawIds = $connection->fetchFirstColumn(
			'SELECT id FROM object_lookup WHERE site_id = :siteId AND type = :type AND id > :afterId ORDER BY id ASC LIMIT :limit',
			[
				'siteId' => $siteId,
				'type' => 'page',
				'afterId' => $afterId,
				'limit' => $limit,
			],
			[
				'siteId' => ParameterType::INTEGER,
				'type' => ParameterType::STRING,
				'afterId' => ParameterType::INTEGER,
				'limit' => ParameterType::INTEGER,
			],
		);
		if ([] === $rawIds) {
			return [];
		}

		$ids = array_map(static fn (mixed $id): int => (int) $id, $rawIds);
		$rows = $connection->fetchAllAssociative(
			'SELECT object_id, name, value FROM object_properties WHERE object_id IN (:ids) AND name IN (:names) AND secret = false',
			[
				'ids' => $ids,
				'names' => ['slug', 'settings'],
			],
			[
				'ids' => ArrayParameterType::INTEGER,
				'names' => ArrayParameterType::STRING,
			],
		);

		$byId = [];
		foreach ($ids as $id) {
			$byId[$id] = ['id' => $id, 'slug' => null, 'settings' => []];
		}

		foreach ($rows as $row) {
			$objectId = (int) $row['object_id'];
			if (!isset($byId[$objectId])) {
				continue;
			}
			$rawValue = $row['value'];
			$decoded = is_string($rawValue) ? json_decode($rawValue, true) : $rawValue;
			if ('slug' === $row['name'] && is_string($decoded)) {
				$byId[$objectId]['slug'] = $decoded;
			} elseif ('settings' === $row['name'] && is_array($decoded)) {
				$byId[$objectId]['settings'] = $decoded;
			}
		}

		return array_values($byId);
	}
}
