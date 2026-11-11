<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Doctrine\ObjectPartitionManager;
use PHPUnit\Framework\TestCase;

final class ObjectPartitionManagerTest extends TestCase
{
	public function testCreatesSiteAndQuarterPartitionsUsingSafeDateBounds(): void
	{
		$connection = $this->createMock(Connection::class);
		$connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '"'.$name.'"');
		$connection->method('quote')->willReturnCallback(static fn (string $value): string => "'".$value."'");
		$connection->expects(self::exactly(2))->method('fetchOne')->willReturn(true);
		$statements = [];
		$connection->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(
			static function (string $sql) use (&$statements): int {
				$statements[] = $sql;

				return 0;
			},
		);

		$names = new DatabaseTableNameResolver($connection);
		$manager = new ObjectPartitionManager($connection, $names);
		$manager->ensurePartition('007', new \DateTimeImmutable('2026-11-11T00:00:00Z'));

		self::assertStringContainsString('FOR VALUES IN (7)', $statements[0]);
		self::assertStringContainsString('FROM (\'2026-10-01\') TO (\'2027-01-01\')', $statements[1]);
		self::assertSame('7', ObjectPartitionManager::normalizeSiteId('007'));
		self::assertSame('0', ObjectPartitionManager::normalizeSiteId('0'));
	}

	public function testRejectsNonDecimalSiteIdsBeforeExecutingDdl(): void
	{
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::never())->method('fetchOne');
		$connection->expects(self::never())->method('executeStatement');
		$manager = new ObjectPartitionManager($connection, new DatabaseTableNameResolver($connection));

		$this->expectException(\InvalidArgumentException::class);
		$manager->ensurePartition('-1', new \DateTimeImmutable('2026-11-11'));
	}
}
