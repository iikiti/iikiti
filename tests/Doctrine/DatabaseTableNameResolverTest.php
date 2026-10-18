<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use PHPUnit\Framework\TestCase;

final class DatabaseTableNameResolverTest extends TestCase
{
	public function testQualifiesUnqualifiedProjectTablesOnlyWhenSchemaIsConfigured(): void
	{
		$connection = $this->createStub(Connection::class);
		$connection->method('quoteIdentifier')->willReturnCallback(static fn (string $identifier): string => '"'.$identifier.'"');
		$resolver = new DatabaseTableNameResolver($connection, 'cms_data');

		self::assertSame('"cms_data"."batch_jobs"', $resolver->resolve('batch_jobs'));
		self::assertSame('SELECT * FROM "cms_data"."batch_jobs" j WHERE j.id = :id', $resolver->qualifySql('SELECT * FROM batch_jobs j WHERE j.id = :id'));
		self::assertSame('SELECT 1', $resolver->qualifySql('SELECT 1'));
	}

	public function testLeavesDefaultSchemaSqlUnqualified(): void
	{
		$connection = $this->createStub(Connection::class);
		$resolver = new DatabaseTableNameResolver($connection);

		self::assertSame('SELECT * FROM batch_jobs', $resolver->qualifySql('SELECT * FROM batch_jobs'));
	}
}
