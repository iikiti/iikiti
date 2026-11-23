<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Doctrine\UnknownDbObjectClassGenerator;
use iikiti\CMS\Entity\Attribute\DbObjectDiscriminator;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\UnknownDbObject;
use iikiti\CMS\Event\Listener\DynamicDiscriminatorMapListener;
use iikiti\CMS\Event\Listener\UnknownDbObjectGuardListener;
use iikiti\CMS\Plugin\DbObjectSubtypeProviderInterface;
use PHPUnit\Framework\TestCase;

#[ORM\Entity]
#[ORM\Table(name: 'object_lookup')]
#[DbObjectDiscriminator('acme.custom_widget')]
class CustomAttributedPluginObject extends DbObject
{
}

#[ORM\Entity]
#[ORM\Table(name: 'object_lookup')]
class ProviderRegisteredPluginObject extends DbObject
{
}

final class DynamicDiscriminatorMapTest extends TestCase
{
	private string $cacheDir;

	protected function setUp(): void
	{
		$this->cacheDir = sys_get_temp_dir().'/iikiti-unknown-obj-'.bin2hex(random_bytes(6));
	}

	protected function tearDown(): void
	{
		if (is_dir($this->cacheDir)) {
			$files = glob($this->cacheDir.'/*');
			if (is_array($files)) {
				foreach ($files as $file) {
					@unlink($file);
				}
			}
			@rmdir($this->cacheDir);
		}
	}

	public function testAttributeAndProviderRegisterDynamicStiSubtypes(): void
	{
		$provider = new class implements DbObjectSubtypeProviderInterface {
			public function getDbObjectSubtypes(): array
			{
				return ['acme.provider_item' => ProviderRegisteredPluginObject::class];
			}
		};

		$em = $this->createSqliteEntityManager(
			paths: [dirname(__DIR__, 2).'/src/Entity', __DIR__],
			providers: [$provider],
			withDbData: false,
		);

		$meta = $em->getClassMetadata(DbObject::class);
		self::assertSame(CustomAttributedPluginObject::class, $meta->discriminatorMap['acme.custom_widget'] ?? null);
		self::assertSame(ProviderRegisteredPluginObject::class, $meta->discriminatorMap['acme.provider_item'] ?? null);
	}

	public function testDuplicateDiscriminatorFailsFast(): void
	{
		$provider = new class implements DbObjectSubtypeProviderInterface {
			public function getDbObjectSubtypes(): array
			{
				return ['page' => ProviderRegisteredPluginObject::class];
			}
		};

		$em = $this->createSqliteEntityManager(
			paths: [dirname(__DIR__, 2).'/src/Entity'],
			providers: [$provider],
			withDbData: false,
		);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('Duplicate DbObject discriminator "page"');
		$em->getClassMetadata(DbObject::class);
	}

	public function testUnloadedDiscriminatorHydratesAsReadOnlyUnknownDbObject(): void
	{
		$em = $this->createSqliteEntityManager(
			paths: [dirname(__DIR__, 2).'/src/Entity'],
			providers: [],
			withDbData: true,
		);

		$connection = $em->getConnection();
		$connection->executeStatement("INSERT INTO object_lookup (id, site_id, created_date, creator_id, type) VALUES (99, 1, '2026-11-22 12:00:00', 1, 'retired_plugin.widget')");

		$rootMeta = $em->getClassMetadata(DbObject::class);
		self::assertArrayHasKey('retired_plugin.widget', $rootMeta->discriminatorMap);
		$proxyClass = $rootMeta->discriminatorMap['retired_plugin.widget'];
		self::assertTrue(is_subclass_of($proxyClass, UnknownDbObject::class));

		$hydrated = $em->find(DbObject::class, 99);
		self::assertInstanceOf(UnknownDbObject::class, $hydrated);
		self::assertSame('retired_plugin.widget', $hydrated->getOriginalType());

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('Cannot persist UnknownDbObject');
		$em->persist(new $proxyClass());
	}

	public function testConsumerModeSkipsDatabaseLookupForUnknownDiscriminators(): void
	{
		$em = $this->createSqliteEntityManager(
			paths: [dirname(__DIR__, 2).'/src/Entity'],
			providers: [],
			withDbData: true,
			applicationRole: 'consumer',
		);

		$em->getConnection()->executeStatement("INSERT INTO object_lookup (id, site_id, created_date, creator_id, type) VALUES (101, 1, '2026-11-22 12:00:00', 1, 'consumer_ignored.widget')");

		$rootMeta = $em->getClassMetadata(DbObject::class);
		self::assertArrayNotHasKey('consumer_ignored.widget', $rootMeta->discriminatorMap);
	}

	/**
	 * @param list<string>                                $paths
	 * @param list<DbObjectSubtypeProviderInterface>      $providers
	 */
	private function createSqliteEntityManager(
		array $paths,
		array $providers,
		bool $withDbData,
		string $applicationRole = 'api',
	): EntityManagerInterface {
		$generator = new UnknownDbObjectClassGenerator($this->cacheDir);
		$generator->ensureDirectory();

		$config = ORMSetup::createAttributeMetadataConfig(
			paths: array_merge($paths, [$this->cacheDir]),
			isDevMode: true,
		);
		$config->enableNativeLazyObjects(true);

		$connection = DriverManager::getConnection([
			'driver' => 'pdo_sqlite',
			'memory' => true,
		], $config);

		if ($withDbData) {
			$schemaConfig = ORMSetup::createAttributeMetadataConfig(
				paths: array_merge($paths, [$this->cacheDir]),
				isDevMode: true,
			);
			$schemaConfig->enableNativeLazyObjects(true);
			$schemaEm = new EntityManager($connection, $schemaConfig);
			$schemaTool = new SchemaTool($schemaEm);
			$schemaTool->createSchema([
				$schemaEm->getClassMetadata(DbObject::class),
				$schemaEm->getClassMetadata(\iikiti\CMS\Entity\Plugin\PluginRecord::class),
			]);
		}

		$em = new EntityManager($connection, $config);

		$registry = $this->createStub(ManagerRegistry::class);
		$registry->method('getManager')->willReturn($em);

		$listener = new DynamicDiscriminatorMapListener(
			registry: $registry,
			subtypeProviders: $providers,
			unknownClassGenerator: $generator,
			applicationRole: $applicationRole,
		);
		$guard = new UnknownDbObjectGuardListener();

		$em->getEventManager()->addEventListener([\Doctrine\ORM\Events::loadClassMetadata], $listener);
		$em->getEventManager()->addEventListener([\Doctrine\ORM\Events::prePersist, \Doctrine\ORM\Events::preUpdate], $guard);

		return $em;
	}
}
