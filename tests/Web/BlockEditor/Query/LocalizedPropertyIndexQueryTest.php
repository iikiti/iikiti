<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Query;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Plugin\ObjectPropertyDefinition;
use iikiti\CMS\Plugin\ObjectPropertyDefinitionProviderInterface;
use iikiti\CMS\Plugin\ObjectPropertyDefinitionRegistry;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\LocaleCollationManager;
use iikiti\CMS\Service\LocaleResolver;
use iikiti\CMS\Value\LocaleTag;
use iikiti\CMS\Web\BlockEditor\Query\LocalizedPropertyIndexQuery;
use iikiti\CMS\Web\BlockEditor\Query\QueryDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class LocalizedPropertyIndexQueryTest extends TestCase
{
	public function testLeavesNonPropertyQueriesOnTheExistingQueryPath(): void
	{
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::never())->method('fetchFirstColumn');
		$query = $this->query($connection, new ObjectPropertyDefinitionRegistry([]));

		self::assertNull($query->queryIds(QueryDefinition::fromArray([
			'filters' => [['field' => 'id', 'op' => 'eq', 'value' => 5]],
		]), 2));
	}

	public function testRejectsUndeclaredIndexedPropertyFields(): void
	{
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::never())->method('fetchFirstColumn');
		$query = $this->query($connection, new ObjectPropertyDefinitionRegistry([]));

		$this->expectException(\InvalidArgumentException::class);
		$query->queryIds(QueryDefinition::fromArray([
			'filters' => [['field' => 'properties.private_notes', 'op' => 'eq', 'value' => 'secret']],
		]), 2);
	}

	public function testQueriesDeclaredFieldsUsingLocaleSpecificIndexedCollation(): void
	{
		$site = new Site();
		$site->setLocaleSettings('en-CA', ['en-CA'], ['en-CA']);
		$siteRepository = $this->createStub(SiteRepository::class);
		$siteRepository->method('find')->willReturn($site);
		$siteRepository->method('getApplicationBySite')->willReturn(null);
		$user = new User();
		$user->setPreferredLocale('en-CA');
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn($user);

		$connection = $this->createStub(Connection::class);
		$connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '"'.$name.'"');
		[$collationName, $indexName] = LocaleCollationManager::names(LocaleTag::fromString('en-CA'));
		$connection->method('fetchAssociative')->willReturn([
			'collation_name' => $collationName,
			'btree_index_name' => $indexName,
			'collation_version' => '153.136',
		]);
		$connection->method('fetchOne')->willReturnCallback(static function (string $sql) use ($indexName): string {
			return str_contains($sql, 'to_regclass') ? $indexName : '153.136';
		});
		$queries = [];
		$connection->method('fetchFirstColumn')->willReturnCallback(static function (string $sql) use (&$queries): array {
			if (str_contains($sql, 'SELECT DISTINCT query_locale')) {
				return ['en-CA'];
			}
			$queries[] = $sql;

			return ['42'];
		});

		$provider = $this->createStub(ObjectPropertyDefinitionProviderInterface::class);
		$provider->method('getObjectPropertyDefinitions')->willReturn([
			new ObjectPropertyDefinition('*', 'title', allowAnyLocale: true, searchable: true, sortable: true),
		]);
		$query = $this->query($connection, new ObjectPropertyDefinitionRegistry([$provider]), $siteRepository, $security);
		$ids = $query->queryIds(QueryDefinition::fromArray([
			'filters' => [['field' => 'title', 'op' => 'eq', 'value' => 'Colour']],
			'orderBy' => [['field' => 'title', 'dir' => 'desc']],
		]), 2);

		self::assertSame(['42'], $ids);
		self::assertCount(1, $queries);
		self::assertStringContainsString('COLLATE "iikiti_iikiti"."'.$collationName.'"', $queries[0]);
		self::assertStringContainsString('ORDER BY localized_0.value COLLATE', $queries[0]);
	}

	public function testFullTextFilterBindsThePostgreSqlConfigForTheRegionalQueryLocale(): void
	{
		$site = new Site();
		$site->setLocaleSettings('fr-CA', ['fr-CA'], ['fr-CA']);
		$siteRepository = $this->createStub(SiteRepository::class);
		$siteRepository->method('find')->willReturn($site);
		$siteRepository->method('getApplicationBySite')->willReturn(null);
		$user = new User();
		$user->setPreferredLocale('fr-CA');
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn($user);
		$provider = $this->createStub(ObjectPropertyDefinitionProviderInterface::class);
		$provider->method('getObjectPropertyDefinitions')->willReturn([
			new ObjectPropertyDefinition('*', 'content', searchable: true, allowAnyLocale: true),
		]);
		$connection = $this->createStub(Connection::class);
		$connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '"'.$name.'"');
		[$collationName, $indexName] = LocaleCollationManager::names(LocaleTag::fromString('fr-CA'));
		$connection->method('fetchAssociative')->willReturn([
			'collation_name' => $collationName,
			'btree_index_name' => $indexName,
			'collation_version' => '153.136',
		]);
		$connection->method('fetchOne')->willReturnCallback(static fn (string $sql): string => str_contains($sql, 'to_regclass') ? $indexName : '153.136');
		$queries = [];
		$params = [];
		$connection->method('fetchFirstColumn')->willReturnCallback(static function (string $sql, array $bound = []) use (&$queries, &$params): array {
			if (str_contains($sql, 'SELECT DISTINCT query_locale')) {
				return ['fr-CA'];
			}
			$queries[] = $sql;
			$params = $bound;

			return ['99'];
		});
		$query = $this->query($connection, new ObjectPropertyDefinitionRegistry([$provider]), $siteRepository, $security);

		self::assertSame(['99'], $query->queryIds(QueryDefinition::fromArray([
			'filters' => [['field' => 'properties.content', 'op' => 'fts', 'value' => 'bonjour']],
		]), 2));
		self::assertStringContainsString('search_vector @@ websearch_to_tsquery', $queries[0]);
		self::assertSame('french', $params['text_config_1']);
	}

	private function query(
		Connection $connection,
		ObjectPropertyDefinitionRegistry $definitions,
		?SiteRepository $siteRepository = null,
		?Security $security = null,
	): LocalizedPropertyIndexQuery {
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('getConnection')->willReturn($connection);
		$siteRepository ??= $this->createStub(SiteRepository::class);
		$security ??= $this->createStub(Security::class);
		$tables = new DatabaseTableNameResolver($connection, 'iikiti_iikiti');

		return new LocalizedPropertyIndexQuery(
			$entityManager,
			$tables,
			$definitions,
			new LocaleResolver(),
			new LocaleCollationManager($connection, $tables),
			new \iikiti\CMS\Service\PostgreSQLTextSearchConfigResolver(),
			$siteRepository,
			$security,
		);
	}
}
