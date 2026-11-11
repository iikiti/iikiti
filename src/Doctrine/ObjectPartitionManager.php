<?php

declare(strict_types=1);

namespace iikiti\CMS\Doctrine;

use Doctrine\DBAL\Connection;

/** Creates the site and calendar-quarter partitions needed by object writes. */
final readonly class ObjectPartitionManager
{
	public function __construct(
		private Connection $connection,
		private DatabaseTableNameResolver $tableNames,
	) {
	}

	public function ensurePartition(int|string $siteId, \DateTimeInterface $createdDate): void
	{
		$siteId = self::normalizeSiteId($siteId);
		$year = (int) $createdDate->format('Y');
		$month = (int) $createdDate->format('n');
		$quarter = intdiv($month - 1, 3) + 1;
		$startMonth = (($quarter - 1) * 3) + 1;
		$start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $startMonth));
		$end = $start->modify('+3 months');
		$sitePartition = sprintf('objects_site_%s', $siteId);
		$datePartition = sprintf('objects_site_%s_%04d_q%d', $siteId, $year, $quarter);
		$lockKey = sprintf('iikiti.objects.partition.site.%s', $siteId);

		$this->connection->fetchOne('SELECT pg_advisory_lock(hashtext(:lock_key))', ['lock_key' => $lockKey]);
		try {
			$this->connection->executeStatement(sprintf(
				'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES IN (%s) PARTITION BY RANGE (%s)',
				$this->tableNames->resolve($sitePartition),
				$this->tableNames->resolve('objects'),
				$siteId,
				$this->connection->quoteIdentifier('created_date'),
			));
			$this->connection->executeStatement(sprintf(
				'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)',
				$this->tableNames->resolve($datePartition),
				$this->tableNames->resolve($sitePartition),
				$this->connection->quote($start->format('Y-m-d')),
				$this->connection->quote($end->format('Y-m-d')),
			));
		} finally {
			$this->connection->fetchOne('SELECT pg_advisory_unlock(hashtext(:lock_key))', ['lock_key' => $lockKey]);
		}
	}

	public static function normalizeSiteId(int|float|string $siteId): string
	{
		if (is_float($siteId)) {
			if (!is_finite($siteId) || $siteId < 0 || floor($siteId) !== $siteId) {
				throw new \InvalidArgumentException('Object site IDs must be non-negative integers.');
			}
			$siteId = sprintf('%.0f', $siteId);
		}
		$siteId = (string) $siteId;
		if (1 !== preg_match('/^\d+$/', $siteId)) {
			throw new \InvalidArgumentException('Object site IDs must be non-negative decimal integers.');
		}

		return ltrim($siteId, '0') ?: '0';
	}
}
