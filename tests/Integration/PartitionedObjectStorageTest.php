<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

require_once dirname(__DIR__, 2).'/migrations/Version20261111030000.php';
require_once dirname(__DIR__, 2).'/migrations/Version20261111040000.php';

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\ORMSetup;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Doctrine\ObjectPartitionManager;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Plugin\CoreObjectPropertyDefinitionProvider;
use iikiti\CMS\Plugin\ObjectPropertyDefinitionRegistry;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\LocaleCollationManager;
use iikiti\CMS\Service\LocaleResolver;
use iikiti\CMS\Service\LocalizedPropertyIndexUpdater;
use iikiti\CMS\Service\PostgreSQLTextSearchConfigResolver;
use iikiti\CMS\Value\LocalizedValue;
use iikiti\CMS\Web\BlockEditor\Query\LocalizedPropertyIndexQuery;
use iikiti\CMS\Web\BlockEditor\Query\QueryDefinition;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[ORM\Entity]
#[ORM\Table(name: 'object_lookup')]
class PartitionedObjectStorageProbe
{
	#[ORM\Id]
	#[ORM\Column(name: 'id', type: 'bigint')]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	public ?int $id = null;

	#[ORM\Column(name: 'site_id', type: 'bigint')]
	public int $siteId;

	#[ORM\Column(name: 'created_date', type: 'datetime_immutable')]
	public \DateTimeImmutable $createdDate;

	#[ORM\Column(name: 'creator_id', type: 'bigint')]
	public int $creatorId;

	#[ORM\Column(name: 'type', type: 'string')]
	public string $type = 'page';

	#[ORM\Column(name: 'slug', type: 'string', length: 64, nullable: true)]
	public ?string $slug = null;
}

#[ORM\Entity]
#[ORM\Table(name: 'object_reference')]
class PartitionedObjectStorageReferenceProbe
{
	#[ORM\Id]
	#[ORM\Column(name: 'id', type: 'bigint')]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	public ?int $id = null;

	#[ORM\ManyToOne(targetEntity: PartitionedObjectStorageProbe::class)]
	#[ORM\JoinColumn(name: 'object_id', referencedColumnName: 'id')]
	public PartitionedObjectStorageProbe $object;
}

class PartitionedObjectStorageTestSiteRepository extends SiteRepository
{
	public function __construct(private readonly Site $site)
	{
	}

	public function find($id, $lockMode = null, $lockVersion = null, array $options = []): ?object
	{
		return $this->site;
	}

	public function getApplicationBySite(Site $site): ?Application
	{
		return null;
	}
}

final class PartitionedObjectStorageTest extends TestCase
{
	private ?Connection $connection = null;
	private ?EntityManager $entityManager = null;
	private string $schema = '';
	private mixed $originalDbSchema = null;
	private mixed $originalServerDbSchema = null;

	protected function setUp(): void
	{
		if ('1' !== getenv('PARTITION_IDENTITY_TEST_DATABASE')) {
			self::markTestSkipped('Set PARTITION_IDENTITY_TEST_DATABASE=1 with a disposable PostgreSQL database to run the migration rehearsal.');
		}

		$params = $this->connectionParams((string) ($_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL')));
		if (!str_contains($params['dbname'], 'partition')) {
			throw new \RuntimeException('Partition migration integration tests require a dedicated database whose name contains "partition".');
		}
		$this->connection = DriverManager::getConnection($params);
		$this->schema = 'partition_cutover_'.bin2hex(random_bytes(4));
		$this->originalDbSchema = $_ENV['DB_SCHEMA'] ?? null;
		$this->originalServerDbSchema = $_SERVER['DB_SCHEMA'] ?? null;
		$_ENV['DB_SCHEMA'] = $this->schema;
		$_SERVER['DB_SCHEMA'] = $this->schema;
		$this->connection->executeStatement(sprintf('CREATE SCHEMA %s', $this->connection->quoteIdentifier($this->schema)));
		$sql = file_get_contents(__DIR__.'/../Fixtures/partitioned-objects-source-schema.sql');
		if (false === $sql) {
			throw new \RuntimeException('Partition source schema fixture is missing.');
		}
		$nativeConnection = $this->connection->getNativeConnection();
		if (!$nativeConnection instanceof \PDO) {
			throw new \RuntimeException('The PostgreSQL cutover fixture requires a PDO connection.');
		}
		$nativeConnection->exec(str_replace('__SCHEMA__', $this->connection->quoteIdentifier($this->schema), $sql));
		$this->connection->executeStatement(sprintf('SET search_path TO %s, public', $this->connection->quoteIdentifier($this->schema)));

		$this->executeMigration($this->createMigration('DoctrineMigrations\\Version20261111030000'));
		$this->executeMigration($this->createMigration('DoctrineMigrations\\Version20261111040000'));
	}

	protected function tearDown(): void
	{
		$this->entityManager?->close();
		if (null !== $this->connection && '' !== $this->schema) {
			$this->connection->executeStatement(sprintf('DROP SCHEMA %s CASCADE', $this->connection->quoteIdentifier($this->schema)));
			$this->connection->close();
		}
		if (null === $this->originalDbSchema) {
			unset($_ENV['DB_SCHEMA'], $_SERVER['DB_SCHEMA']);
		} else {
			$_ENV['DB_SCHEMA'] = $this->originalDbSchema;
			$_SERVER['DB_SCHEMA'] = $this->originalServerDbSchema;
		}
		parent::tearDown();
	}

	public function testIdentityViewPreservesDoctrineCrudAssociationsAndPartitionPruning(): void
	{
		$connection = $this->connection();
		self::assertSame(6, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s.object_identity', $this->qualifiedSchema())));
		self::assertSame(6, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s.objects', $this->qualifiedSchema())));
		self::assertSame('p', $connection->fetchOne(
			'SELECT relkind FROM pg_class WHERE oid = to_regclass(:table)',
			['table' => $this->schema.'.objects'],
		));
		self::assertSame('v', $connection->fetchOne(
			'SELECT relkind FROM pg_class WHERE oid = to_regclass(:table)',
			['table' => $this->schema.'.object_lookup'],
		));
		$siteTransferFk = (string) $connection->fetchOne("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'fk_site_transfer_target_object'");
		self::assertStringContainsString('object_identity', $siteTransferFk);
		self::assertStringContainsString('ON DELETE CASCADE', $siteTransferFk);
		$workflowActorFk = (string) $connection->fetchOne("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'fk_workflow_instance_event_actor'");
		self::assertStringContainsString('object_identity', $workflowActorFk);
		self::assertStringContainsString('ON DELETE SET NULL', $workflowActorFk);
		self::assertSame(1, (int) $connection->fetchOne(sprintf(
			'SELECT COUNT(*) FROM %s.object_properties WHERE object_id = 9999',
			$this->qualifiedSchema(),
		)));
		$propertyForeignKeyValidated = $connection->fetchOne(sprintf(
			"SELECT convalidated FROM pg_constraint WHERE conname = 'fk_object_properties_identity' AND conrelid = to_regclass('%s.object_properties')",
			$this->schema,
		));
		self::assertContains($propertyForeignKeyValidated, [false, 0, '0', 'f']);
		$this->assertLocalizedIndexPlans();

		$config = ORMSetup::createAttributeMetadataConfiguration([__DIR__], true);
		$config->enableNativeLazyObjects(true);
		$entityManager = $this->entityManager = new EntityManager($connection, $config);
		$object = new PartitionedObjectStorageProbe();
		$object->siteId = 2;
		$object->createdDate = new \DateTimeImmutable('2026-11-11T00:00:00');
		$object->creatorId = 1;
		$object->slug = 'identity-'.bin2hex(random_bytes(6));
		$entityManager->persist($object);
		$entityManager->flush();
		$id = $object->id;
		self::assertGreaterThan(100, $id);

		$reference = new PartitionedObjectStorageReferenceProbe();
		$reference->object = $object;
		$entityManager->persist($reference);
		$entityManager->flush();
		$referenceId = $reference->id;
		$entityManager->clear();
		$loadedReference = $entityManager->find(PartitionedObjectStorageReferenceProbe::class, $referenceId);
		self::assertInstanceOf(PartitionedObjectStorageReferenceProbe::class, $loadedReference);
		self::assertSame($object->slug, $loadedReference->object->slug);

		$explain = $connection->fetchFirstColumn(
			'EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT) SELECT id FROM '.$this->qualifiedSchema().'.object_lookup WHERE id = :id',
			['id' => $id],
		);
		$plan = implode("\n", $explain);
		self::assertStringContainsString('uniq_identity_partition_key', $plan);
		self::assertStringContainsString('objects_site_2_2026_q4', $plan);

		$manager = new ObjectPartitionManager($connection, new DatabaseTableNameResolver($connection, $this->schema));
		$manager->ensurePartition(3, new \DateTimeImmutable('2027-02-10T00:00:00'));
		$loadedReference->object->siteId = 3;
		$loadedReference->object->createdDate = new \DateTimeImmutable('2027-02-10T00:00:00');
		$entityManager->flush();
		$entityManager->clear();
		$moved = $entityManager->find(PartitionedObjectStorageProbe::class, $id);
		self::assertSame(3, $moved->siteId);
		self::assertSame('2027-02-10', $moved->createdDate->format('Y-m-d'));

		$entityManager->remove($moved);
		$entityManager->flush();
		self::assertSame(0, (int) $connection->fetchOne(sprintf(
			'SELECT COUNT(*) FROM %s.object_reference WHERE object_id = :id',
			$this->qualifiedSchema(),
		), ['id' => $id]));
		$entityManager->close();
	}

	public function testLocalizedPropertyProjectionAndQueryResolveRegionalContent(): void
	{
		$connection = $this->connection();
		$config = ORMSetup::createAttributeMetadataConfiguration([__DIR__], true);
		$config->enableNativeLazyObjects(true);
		$entityManager = $this->entityManager = new EntityManager($connection, $config);
		$objectId = (int) $connection->fetchOne(sprintf(
			'SELECT object_id FROM %s.object_properties WHERE name = \'title\' ORDER BY id LIMIT 1',
			$this->qualifiedSchema(),
		));
		$propertyId = (int) $connection->fetchOne(sprintf(
			'SELECT id FROM %s.object_properties WHERE object_id = :object_id AND name = \'title\' ORDER BY id LIMIT 1',
			$this->qualifiedSchema(),
		), ['object_id' => $objectId]);

		$site = new Site();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($site, 2);
		$site->setLocaleSettings('en-CA', ['en-CA', 'fr-CA'], ['en-CA', 'fr-CA']);
		$siteRepository = new PartitionedObjectStorageTestSiteRepository($site);
		$user = new User();
		$user->setPreferredLocale('fr-CA');
		$tokenStorage = new TokenStorage();
		$tokenStorage->setToken(new UsernamePasswordToken($user, 'main'));
		$security = new Security(new ServiceLocator([
			'security.token_storage' => static fn (): TokenStorage => $tokenStorage,
		]));
		$tables = new DatabaseTableNameResolver($connection, $this->schema);
		$definitions = new ObjectPropertyDefinitionRegistry([new CoreObjectPropertyDefinitionProvider()]);
		$localeResolver = new LocaleResolver();
		$updater = new LocalizedPropertyIndexUpdater(
			$connection,
			$tables,
			$definitions,
			$localeResolver,
			new LocaleCollationManager($connection, $tables),
			new PostgreSQLTextSearchConfigResolver(),
			$siteRepository,
			$entityManager,
		);

		$page = new Page();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($page, $objectId);
		(new \ReflectionProperty(DbObject::class, 'type'))->setValue($page, 'page');
		$page->setSiteId(2);
		$page->setLocaleSettings('en-CA', null, ['en-CA', 'fr-CA']);
		$property = new ObjectProperty();
		(new \ReflectionProperty(ObjectProperty::class, 'id'))->setValue($property, $propertyId);
		$property->setName('title');
		$property->setObject($page);
		$property->setValue(LocalizedValue::fromTranslations(['en-CA' => 'Color', 'fr-CA' => 'Couleur']));
		$updater->refresh($property);

		$query = new LocalizedPropertyIndexQuery(
			$entityManager,
			$tables,
			$definitions,
			$localeResolver,
			new LocaleCollationManager($connection, $tables),
			new PostgreSQLTextSearchConfigResolver(),
			$siteRepository,
			$security,
		);
		$ids = $query->queryIds(QueryDefinition::fromArray([
			'objectType' => 'page',
			'filters' => [['field' => 'properties.title', 'op' => 'eq', 'value' => 'Couleur']],
			'orderBy' => [['field' => 'properties.title', 'dir' => 'asc']],
			'limit' => 10,
		]), 2);

		self::assertSame([$objectId], $ids);
		self::assertSame(['Couleur'], $connection->fetchFirstColumn(sprintf(
			'SELECT value FROM %s.object_property_locale_index WHERE object_id = :object_id AND site_id = 2 AND query_locale = \'fr-CA\'',
			$this->qualifiedSchema(),
		), ['object_id' => $objectId]));
	}

	private function assertLocalizedIndexPlans(): void
	{
		$connection = $this->connection();
		$schema = $this->qualifiedSchema();
		$propertyId = (int) $connection->fetchOne(sprintf('SELECT id FROM %s.object_properties WHERE name = \'title\' LIMIT 1', $schema));
		$objectId = (int) $connection->fetchOne(sprintf('SELECT object_id FROM %s.object_properties WHERE id = :id', $schema), ['id' => $propertyId]);
		$connection->executeStatement(sprintf(
			"INSERT INTO %s.object_property_locale_index (object_property_id, object_id, object_site_id, site_id, object_type, property_name, query_locale, source_locale, value, is_searchable, is_sortable, search_vector) VALUES (:property_id, :object_id, 2, 2, 'page', 'title', 'und', 'und', 'Home', TRUE, TRUE, to_tsvector('simple', 'Home'))",
			$schema,
		), ['property_id' => $propertyId, 'object_id' => $objectId]);
		$connection->executeStatement('SET enable_seqscan = off');
		$collation = $this->connection()->quoteIdentifier($this->schema).'.'.$this->connection()->quoteIdentifier('iikiti_locale_und');
		$sortPlan = implode("\n", $connection->fetchFirstColumn(sprintf(
			'EXPLAIN SELECT object_id FROM %s.object_property_locale_index WHERE site_id = 2 AND object_type = \'page\' AND property_name = \'title\' AND query_locale = \'und\' ORDER BY value COLLATE %s',
			$schema,
			$collation,
		)));
		$fullTextPlan = implode("\n", $connection->fetchFirstColumn(sprintf(
			"EXPLAIN SELECT object_id FROM %s.object_property_locale_index WHERE is_searchable = TRUE AND search_vector @@ to_tsquery('simple', 'home')",
			$schema,
		)));
		$substringPlan = implode("\n", $connection->fetchFirstColumn(sprintf(
			"EXPLAIN SELECT object_id FROM %s.object_property_locale_index WHERE is_searchable = TRUE AND value LIKE '%%Hom%%'",
			$schema,
		)));

		self::assertStringContainsString('idx_localized_property_sort_und', $sortPlan);
		self::assertStringContainsString('idx_localized_property_search_vector', $fullTextPlan);
		self::assertStringContainsString('idx_localized_property_value_trgm', $substringPlan);
	}

	private function executeMigration(AbstractMigration $migration): void
	{
		$schema = new Schema();
		$this->connection()->beginTransaction();
		try {
			$migration->up($schema);
			foreach ($migration->getSql() as $query) {
				$this->connection()->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
			}
			$migration->postUp($schema);
			$this->connection()->commit();
		} catch (\Throwable $exception) {
			if ($this->connection()->isTransactionActive()) {
				$this->connection()->rollBack();
			}
			throw $exception;
		}
	}

	private function createMigration(string $class): AbstractMigration
	{
		$migration = (new \ReflectionClass($class))->newInstance($this->connection(), new NullLogger());
		if (!$migration instanceof AbstractMigration) {
			throw new \RuntimeException(sprintf('Migration class %s is invalid.', $class));
		}

		return $migration;
	}

	private function connection(): Connection
	{
		return $this->connection ?? throw new \LogicException('The isolated test database is not connected.');
	}

	private function qualifiedSchema(): string
	{
		return $this->connection()->quoteIdentifier($this->schema);
	}

	/** @return array<string,mixed> */
	private function connectionParams(string $url): array
	{
		$parts = parse_url($url);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['path'])) {
			throw new \RuntimeException('DATABASE_URL is not a valid PostgreSQL URL.');
		}
		if (!in_array($parts['scheme'], ['postgresql', 'postgres', 'pgsql'], true)) {
			throw new \RuntimeException('Partition migration integration tests require PostgreSQL.');
		}
		parse_str($parts['query'] ?? '', $options);
		$params = [
			'driver' => 'pdo_pgsql',
			'host' => $parts['host'] ?? '127.0.0.1',
			'user' => $parts['user'] ?? null,
			'password' => $parts['pass'] ?? null,
			'dbname' => ltrim($parts['path'], '/'),
			'charset' => $options['charset'] ?? 'utf8',
			'serverVersion' => $options['serverVersion'] ?? '18',
		];
		if (isset($parts['port'])) {
			$params['port'] = $parts['port'];
		}

		return $params;
	}
}
