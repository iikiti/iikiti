<?php

namespace iikiti\CMS\Tests\Plugin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Plugin\Lifecycle\PluginLifecycleHandler;
use iikiti\CMS\Plugin\PluginContainerRebuilder;
use iikiti\CMS\Plugin\PluginLoader;
use iikiti\CMS\Plugin\PluginManager;
use iikiti\CMS\Plugin\PluginManifest;
use iikiti\CMS\Plugin\PluginPackage;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Plugin\PluginSource;
use iikiti\CMS\Plugin\PluginState;
use iikiti\CMS\Plugin\PluginValidator;
use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformRegistry;
use iikiti\CMS\Query\DatabasePlatform\PostgreSQL\PostgreSQLPlatformStrategy;
use iikiti\CMS\Query\QueryBuilderFactory;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that plugin record persistence is routed through the query builder
 * with validated identifiers and bound parameters.
 */
final class PluginManagerQueryTest extends TestCase
{
	/** @var list<array{sql:string, params:array<string,mixed>}> */
	private array $sqlCalls = [];

	private ?string $previousDatabaseSchema = null;

	protected function setUp(): void
	{
		// Force registryTableName() through its DB_SCHEMA fallback so the
		// table name is deterministic in the assertions.
		$this->previousDatabaseSchema = getenv('DB_SCHEMA') ?: null;
		putenv('DB_SCHEMA=');
	}

	protected function tearDown(): void
	{
		if (null === $this->previousDatabaseSchema) {
			putenv('DB_SCHEMA');
		} else {
			putenv('DB_SCHEMA='.$this->previousDatabaseSchema);
		}
	}

	public function testRecordInstallInsertsBoundParameters(): void
	{
		$connection = $this->createRecordingConnection();

		[$manager, $manifest, $package] = $this->createManager($connection);
		$this->callRecordInstall($manager, $manifest, $package);

		// SELECT for the existing row: id lookup, slug bound, site_id NULL.
		$select = $this->sqlCalls[0];
		$this->assertSame('SELECT id FROM plugin_registry WHERE (slug = :qp1) AND (site_id IS NULL)', $select['sql']);
		$this->assertSame('test-plugin', $select['params']['qp1']);

		// INSERT with a parameter per column and no inline literals.
		$insert = $this->sqlCalls[1];
		$this->assertStringStartsWith('INSERT INTO plugin_registry (slug, site_id, version, source, state, installed_at) VALUES', $insert['sql']);
		$this->assertStringNotContainsString('?', $insert['sql']);
		$this->assertSame('test-plugin', $insert['params']['qp1']);
		$this->assertNull($insert['params']['qp2']);
		$this->assertSame('1.0.0', $insert['params']['qp3']);
		$this->assertSame('iikiti_store', $insert['params']['qp4']);
		$this->assertSame('published', $insert['params']['qp5']);
	}

	public function testRecordInstallUpdatesWhenRowExists(): void
	{
		$connection = $this->createRecordingConnection(existingId: 42);

		[$manager, $manifest, $package] = $this->createManager($connection);
		$this->callRecordInstall($manager, $manifest, $package);

		$update = $this->sqlCalls[1];
		$this->assertSame(
			'UPDATE plugin_registry SET version = :qp1, source = :qp2, state = :qp3, installed_at = :qp4 WHERE id = :qp5',
			$update['sql']
		);
		$this->assertSame('1.0.0', $update['params']['qp1']);
		$this->assertSame('published', $update['params']['qp3']);
		$this->assertSame(42, $update['params']['qp5']);
	}

	public function testRecordRemoveDeletesBySlug(): void
	{
		$connection = $this->createRecordingConnection();

		[$manager, $manifest, $package] = $this->createManager($connection);
		$this->callRecordRemove($manager, $manifest->slug);

		$delete = $this->sqlCalls[0];
		$this->assertSame('DELETE FROM plugin_registry WHERE slug = :qp1', $delete['sql']);
		$this->assertSame('test-plugin', $this->sqlCalls[0]['params']['qp1']);
	}

	/**
	 * @return array{0:PluginManager,1:PluginManifest,2:PluginPackage}
	 */
	private function createManager(Connection $connection): array
	{
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('getConnection')->willReturn($connection);
		$entityManager->method('getClassMetadata')->willThrowException(new \RuntimeException('not mapped'));

		$factory = new QueryBuilderFactory(
			$connection,
			new DatabasePlatformRegistry([new PostgreSQLPlatformStrategy()], PostgreSQLPlatformStrategy::NAME),
			true
		);

		$manager = new PluginManager(
			$this->createStub(PluginLoader::class),
			$this->createStub(PluginValidator::class),
			$this->createStub(PluginRegistry::class),
			$this->createStub(PluginLifecycleHandler::class),
			$this->createStub(PluginContainerRebuilder::class),
			$factory,
			$entityManager,
			'test'
		);

		$manifest = new PluginManifest(
			'Test',
			'test-plugin',
			'Acme\\Test',
			'Acme\\Test\\TestBundle',
			'1.0.0',
			'1.0.0'
		);
		$package = new PluginPackage(
			'test-plugin',
			'1.0.0',
			'/tmp/test-plugin.tar.gz',
			PluginSource::IikitiStore,
			PluginState::Published
		);

		return [$manager, $manifest, $package];
	}

	private function callRecordInstall(PluginManager $manager, PluginManifest $manifest, PluginPackage $package): void
	{
		$method = new \ReflectionMethod($manager, 'recordInstall');
		$method->invoke($manager, $manifest, $package);
	}

	private function callRecordRemove(PluginManager $manager, string $slug): void
	{
		$method = new \ReflectionMethod($manager, 'recordRemove');
		$method->invoke($manager, $slug);
	}

	private function createRecordingConnection(?int $existingId = null): Connection
	{
		$connection = $this->createStub(Connection::class);
		$connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

		$connection->method('executeQuery')->willReturnCallback(function (string $sql, array $params = [], array $types = [], ...$rest) use ($existingId) {
			$this->sqlCalls[] = ['sql' => $sql, 'params' => $params];

			$result = $this->createStub(Result::class);
			$result->method('fetchOne')->willReturn($existingId ?? false);

			return $result;
		});

		$connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params = [], array $types = []) {
			$this->sqlCalls[] = ['sql' => $sql, 'params' => $params];

			return 1;
		});

		return $connection;
	}
}
