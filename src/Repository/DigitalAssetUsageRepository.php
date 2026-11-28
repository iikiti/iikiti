<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Entity\DigitalAssetUsage;

/** @extends ServiceEntityRepository<DigitalAssetUsage> */
class DigitalAssetUsageRepository extends ServiceEntityRepository
{
	public function __construct(
		ManagerRegistry $registry,
		private readonly ?DatabaseTableNameResolver $tableNames = null,
	) {
		parent::__construct($registry, DigitalAssetUsage::class);
	}

	/**
	 * @return list<DigitalAssetUsage>
	 */
	public function findByAssetId(int|string $assetId): array
	{
		/** @var list<DigitalAssetUsage> $results */
		$results = $this->createQueryBuilder('usage')->
			andWhere('usage.assetId = :assetId')->
			setParameter('assetId', $assetId)->
			orderBy('usage.contextType', 'ASC')->
			addOrderBy('usage.contextId', 'ASC')->
			addOrderBy('usage.fieldPath', 'ASC')->
			addOrderBy('usage.isDraft', 'ASC')->
			getQuery()->
			getResult();

		return $results;
	}

	/**
	 * Batch-counts asset usage references for the given asset IDs.
	 *
	 * @param list<int|string> $assetIds
	 *
	 * @return array<string, int>
	 */
	public function countByAssetIds(array $assetIds): array
	{
		if ([] === $assetIds) {
			return [];
		}

		$normalized = array_values(array_unique(array_map(static fn (int|string $id): string => (string) $id, $assetIds)));

		$rows = $this->createQueryBuilder('usage')->
			select('usage.assetId AS assetId, COUNT(usage.id) AS cnt')->
			andWhere('usage.assetId IN (:assetIds)')->
			setParameter('assetIds', $normalized)->
			groupBy('usage.assetId')->
			getQuery()->
			getArrayResult();

		$counts = [];
		foreach ($rows as $row) {
			$counts[(string) $row['assetId']] = (int) $row['cnt'];
		}

		return $counts;
	}

	/**
	 * Atomically replaces all indexed usages for a single context using DBAL so it
	 * can safely run from Doctrine `postFlush` listeners without triggering recursive ORM flushes.
	 *
	 * @param list<array{assetId: int|string, fieldPath: string, isDraft: bool, siteId: int|string}> $usages
	 */
	public function replaceContextUsages(string $contextType, int|string $contextId, array $usages): void
	{
		$normalizedType = strtolower(trim($contextType));
		$connection = $this->getEntityManager()->getConnection();

		$this->deleteForContext($normalizedType, $contextId);

		if ([] === $usages) {
			return;
		}

		$usagesTable = $this->resolveTableName('digital_asset_usages');
		$objectLookupTable = $this->resolveTableName('object_lookup');
		$sql = sprintf(
			"INSERT INTO %s (asset_id, context_type, context_id, field_path, is_draft, site_id, updated_at) SELECT ol.id, CAST(:contextType AS VARCHAR(32)), CAST(:contextId AS BIGINT), CAST(:fieldPath AS VARCHAR(255)), CAST(:isDraft AS BOOLEAN), CAST(:siteId AS BIGINT), CAST(:updatedAt AS TIMESTAMP) FROM %s ol WHERE ol.id = :assetId AND LOWER(ol.type) IN ('uploadeddigitalasset', 'externaldigitalasset')",
			$usagesTable,
			$objectLookupTable,
		);

		$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
		$seen = [];
		foreach ($usages as $row) {
			$assetId = (int) $row['assetId'];
			if ($assetId < 1) {
				continue;
			}
			$fieldPath = mb_substr(trim($row['fieldPath']), 0, 255);
			$isDraft = (bool) $row['isDraft'];
			$siteId = (int) $row['siteId'];

			$dedupeKey = $assetId.'|'.$fieldPath.'|'.($isDraft ? '1' : '0');
			if (isset($seen[$dedupeKey])) {
				continue;
			}
			$seen[$dedupeKey] = true;

			$connection->executeStatement(
				$sql,
				[
					'assetId' => $assetId,
					'contextType' => $normalizedType,
					'contextId' => (int) $contextId,
					'fieldPath' => $fieldPath,
					'isDraft' => $isDraft,
					'siteId' => $siteId,
					'updatedAt' => $now,
				],
				[
					'assetId' => ParameterType::INTEGER,
					'contextId' => ParameterType::INTEGER,
					'isDraft' => ParameterType::BOOLEAN,
					'siteId' => ParameterType::INTEGER,
				],
			);
		}
	}

	public function deleteForContext(string $contextType, int|string $contextId): void
	{
		$connection = $this->getEntityManager()->getConnection();
		$connection->executeStatement(
			sprintf('DELETE FROM %s WHERE context_type = :contextType AND context_id = :contextId', $this->resolveTableName('digital_asset_usages')),
			[
				'contextType' => strtolower(trim($contextType)),
				'contextId' => (int) $contextId,
			],
			[
				'contextId' => ParameterType::INTEGER,
			],
		);
	}

	public function deleteAll(): void
	{
		$this->getEntityManager()->getConnection()->executeStatement(
			sprintf('DELETE FROM %s', $this->resolveTableName('digital_asset_usages')),
		);
	}

	private function resolveTableName(string $table): string
	{
		if (null !== $this->tableNames) {
			return $this->tableNames->resolve($table);
		}

		return $this->getEntityManager()->getConnection()->quoteSingleIdentifier($table);
	}
}
