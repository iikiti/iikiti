<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Crypto\GuestApiTokenKeyring;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Doctrine\ObjectPartitionManager;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Kernel;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Tests\Support\Async\ApiWorkerServer;
use iikiti\CMS\Web\BlockEditor\PublicPageApiProxy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpFoundation\Request;

/**
 * Layer 2 real PostgreSQL + multi-worker API concurrency integration test.
 *
 * Proves that when one widget's worker blocks on a PostgreSQL advisory lock,
 * a sibling widget continues rendering through Doctrine ORM + Twig on another
 * API worker and releases the lock so the full page completes.
 */
final class AsyncDatabaseConcurrencyIntegrationTest extends TestCase
{
	private ?Connection $connection = null;
	private ?ApiWorkerServer $workerServer = null;
	/** @var list<int> */
	private array $createdObjectIds = [];
	private ?int $createdSiteId = null;
	/** @var array<string,mixed>|null */
	private ?array $dbParams = null;
	/** @var array<string,array{env:string|false,server:mixed,hasServer:bool,environment:mixed,hasEnvironment:bool}> */
	private array $originalEnv = [];

	protected function setUp(): void
	{
		if (!extension_loaded('curl')) {
			$this->markTestSkipped('ext-curl is required for consumer CurlHttpClient integration tests.');
		}
		if (!extension_loaded('pdo_pgsql')) {
			$this->markTestSkipped('ext-pdo_pgsql is required for PostgreSQL concurrency integration tests.');
		}
		$probe = @stream_socket_server('tcp://127.0.0.1:0');
		if (false === $probe) {
			$this->markTestSkipped('Loopback TCP sockets cannot be bound in this environment.');
		}
		fclose($probe);
	}

	protected function tearDown(): void
	{
		$this->workerServer?->stop();
		$this->workerServer = null;

		$cleanupConnection = $this->cleanupConnection();
		if (null !== $cleanupConnection && [] !== $this->createdObjectIds) {
			foreach ($this->createdObjectIds as $id) {
				try {
					$cleanupConnection->executeStatement(
						'DELETE FROM iikiti_iikiti.object_properties WHERE object_id = :id',
						['id' => $id],
						['id' => ParameterType::INTEGER],
					);
				} catch (\Throwable) {
				}
			}
			foreach (array_reverse($this->createdObjectIds) as $id) {
				try {
					$cleanupConnection->executeStatement(
						'DELETE FROM iikiti_iikiti.object_lookup WHERE id = :id',
						['id' => $id],
						['id' => ParameterType::INTEGER],
					);
				} catch (\Throwable) {
				}
			}
			if (null !== $this->createdSiteId) {
				try {
					$cleanupConnection->executeStatement(sprintf('DROP TABLE IF EXISTS iikiti_iikiti.objects_site_%d CASCADE', $this->createdSiteId));
				} catch (\Throwable) {
				}
			}
			$cleanupConnection->close();
		}
		$this->createdObjectIds = [];
		$this->createdSiteId = null;
		$this->connection?->close();
		$this->connection = null;

		foreach ($this->originalEnv as $name => $state) {
			if (false === $state['env']) {
				putenv($name);
			} else {
				putenv($name.'='.$state['env']);
			}
			if ($state['hasServer']) {
				$_SERVER[$name] = $state['server'];
			} else {
				unset($_SERVER[$name]);
			}
			if ($state['hasEnvironment']) {
				$_ENV[$name] = $state['environment'];
			} else {
				unset($_ENV[$name]);
			}
		}
		$this->originalEnv = [];
	}

	public function testSiblingWidgetRendersViaDoctrineWhileAnotherWidgetIsBlockedOnPostgresqlLock(): void
	{
		$this->setEnv('APPLICATION_ROLE', 'api');
		$kernel = new Kernel('test', false);
		$kernel->boot();

		/** @var Connection $connection */
		$connection = $kernel->getContainer()->get('doctrine.dbal.default_connection');
		try {
			$connection->executeQuery('SELECT 1')->fetchOne();
		} catch (\Throwable $exception) {
			$kernel->shutdown();
			$this->markTestSkipped('PostgreSQL test database is unavailable: '.$exception->getMessage());
		}
		$this->connection = $connection;
		$this->dbParams = $connection->getParams();

		$suffix = bin2hex(random_bytes(4));
		$domain = 'async-db-'.$suffix.'.example.test';
		$slug = 'async-db-page-'.$suffix;

		$applicationId = $this->insertObject(0, 'application');
		$siteId = $this->insertObject(0, 'site');
		$this->createdSiteId = $siteId;
		$this->insertProperty($siteId, 'application', $applicationId);
		$this->insertProperty($siteId, Site::DOMAIN_PROPERTY_KEY, $domain);
		(new ObjectPartitionManager($connection, new DatabaseTableNameResolver($connection, 'iikiti_iikiti')))
			->ensurePartition($siteId, new \DateTimeImmutable());

		$templateBlocks = [
			'main' => [
				[
					'id' => 'locked-query-container',
					'type' => 'container',
					'content' => [],
					'style' => ['base' => []],
					'children' => [
						[
							'id' => 'db-query',
							'type' => 'query',
							'content' => [
								'source' => 'objects',
								'objectType' => '',
								'filters' => [],
								'orderBy' => [['field' => 'id', 'direction' => 'asc']],
								'limit' => 5,
							],
							'style' => ['base' => []],
							'children' => [
								[
									'id' => 'query-item-text',
									'type' => 'text',
									'content' => ['content' => '<p>Locked Query Row</p>'],
									'style' => ['base' => []],
								],
							],
						],
					],
				],
				[
					'id' => 'fast-sibling-container',
					'type' => 'container',
					'content' => [],
					'style' => ['base' => []],
					'children' => [
						[
							'id' => 'sibling-text',
							'type' => 'text',
							'content' => ['content' => '<p>Sibling DB Widget Rendered</p>'],
							'style' => ['base' => []],
						],
					],
				],
			],
		];

		$templateId = $this->insertObject($siteId, 'template');
		$this->insertProperty($templateId, 'layout', 'base/fallback-block-editor-content.twig');
		$this->insertProperty($templateId, 'regions', [
			['id' => 'main', 'name' => 'Main content', 'role' => 'main', 'allowed_types' => []],
		]);
		$this->insertProperty($templateId, 'blocks', $templateBlocks);

		$pageId = $this->insertObject($siteId, 'page');
		$this->insertProperty($pageId, 'slug', $slug);
		$this->insertProperty($pageId, 'template_id', $templateId);

		// Resolve exact widget fragment IDs from the API manifest before spawning workers.
		$appSecret = (string) ($kernel->getContainer()->getParameter('kernel.secret') ?: '88329482d392f');
		$keyringJson = (string) ($_SERVER['GUEST_API_TOKEN_KEYRING'] ?? getenv('GUEST_API_TOKEN_KEYRING') ?: '');
		$activeKeyId = (string) ($_SERVER['GUEST_API_TOKEN_ACTIVE_KEY_ID'] ?? getenv('GUEST_API_TOKEN_ACTIVE_KEY_ID') ?: 'test');
		/** @var array<string,string> $decodedKeys */
		$decodedKeys = '' !== $keyringJson ? json_decode($keyringJson, true, flags: JSON_THROW_ON_ERROR) : [];
		$guestTokenManager = new GuestApiTokenManager(
			$appSecret,
			[] !== $decodedKeys ? new GuestApiTokenKeyring($decodedKeys, $activeKeyId) : null,
		);
		$guestToken = $guestTokenManager->issue($domain);
		$manifestRequest = Request::create('https://'.$domain.'/api/public/render/pages/'.$slug.'/manifest');
		$manifestRequest->headers->set('X-SITE-HOST', $domain);
		$manifestRequest->headers->set('X-SITE-SCHEME', 'https');
		$manifestRequest->headers->set('X-AUTH-TOKEN', $guestToken);
		$manifestResponse = $kernel->handle($manifestRequest);
		self::assertSame(200, $manifestResponse->getStatusCode(), (string) $manifestResponse->getContent());
		$manifest = json_decode((string) $manifestResponse->getContent(), true, flags: JSON_THROW_ON_ERROR);
		$kernel->terminate($manifestRequest, $manifestResponse);

		$widgetIds = [];
		foreach ($manifest['fragments'] ?? [] as $fragment) {
			if ('widget' === ($fragment['kind'] ?? null)) {
				$widgetIds[] = (string) $fragment['id'];
			}
		}
		self::assertCount(2, $widgetIds);
		[$lockedWidgetId, $siblingWidgetId] = $widgetIds;
		$kernel->shutdown();

		$lockKey = random_int(100_000_000, 999_999_999);
		$this->workerServer = new ApiWorkerServer();
		$this->workerServer->warmKernelCache();
		$this->workerServer->startAdvisoryLockHolder($lockKey);
		$this->workerServer->start($lockedWidgetId, $siblingWidgetId, $lockKey, $appSecret);

		$curl = new CurlHttpClient();
		$api = new BusinessApiClient($curl, $this->workerServer->baseUrl(), 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($curl),
			$guestTokenManager,
		);

		$response = $proxy->page(Request::create('https://'.$domain.'/'.$slug), $slug);
		$html = (string) $response->getContent();
		$timeline = $this->workerServer->timeline();
		$stderr = $this->workerServer->stderr();

		self::assertSame(
			200,
			$response->getStatusCode(),
			'Multi-worker API render should succeed without deadlock. Timeline: '.implode(', ', $timeline).' Stderr: '.$stderr.' Body: '.$html,
		);
		self::assertInstanceOf(DomHtmlResponse::class, $response);
		self::assertStringContainsString('Sibling DB Widget Rendered', $html);
		self::assertStringContainsString('Locked Query Row', $html);

		$waitPos = array_search('lock_wait_start:'.$lockedWidgetId, $timeline, true);
		$siblingPos = array_search('sibling_rendered:'.$siblingWidgetId, $timeline, true);
		$acquiredPos = array_search('lock_acquired:'.$lockedWidgetId, $timeline, true);

		self::assertNotFalse($waitPos, 'Expected locked widget to wait on PostgreSQL advisory lock.');
		self::assertNotFalse($siblingPos, 'Expected sibling widget to render on another worker.');
		self::assertNotFalse($acquiredPos, 'Expected locked widget to acquire lock after sibling signaled.');
		self::assertLessThan($acquiredPos, $siblingPos, 'Sibling widget must render before locked widget can acquire PostgreSQL lock. Timeline: '.implode(', ', $timeline));
	}

	private function insertObject(int $siteId, string $type): int
	{
		self::assertNotNull($this->connection);
		$row = $this->connection->executeQuery(
			'INSERT INTO iikiti_iikiti.object_lookup (created_date, creator_id, site_id, type) VALUES (NOW(), 0, :site_id, :type) RETURNING id',
			['site_id' => $siteId, 'type' => $type],
			['site_id' => ParameterType::INTEGER, 'type' => ParameterType::STRING],
		)->fetchAssociative();
		$id = (int) ($row['id'] ?? 0);
		self::assertGreaterThan(0, $id);
		$this->createdObjectIds[] = $id;

		return $id;
	}

	private function cleanupConnection(): ?Connection
	{
		if (null !== $this->connection && $this->connection->isConnected()) {
			return $this->connection;
		}
		if (null === $this->dbParams) {
			return null;
		}
		try {
			return DriverManager::getConnection($this->dbParams);
		} catch (\Throwable) {
			return null;
		}
	}

	private function insertProperty(int $objectId, string $name, mixed $value): void
	{
		self::assertNotNull($this->connection);
		// ObjectProperty::$creator is a OneToOne to User, so each property needs its own creator row.
		$creatorId = $this->insertObject(0, 'user');
		$this->connection->executeStatement(
			'INSERT INTO iikiti_iikiti.object_properties (object_id, name, value, created, creator_id) VALUES (:object_id, :name, :value, NOW(), :creator_id)',
			[
				'object_id' => $objectId,
				'name' => $name,
				'value' => json_encode($value, JSON_THROW_ON_ERROR),
				'creator_id' => $creatorId,
			],
			[
				'object_id' => ParameterType::INTEGER,
				'name' => ParameterType::STRING,
				'value' => ParameterType::STRING,
				'creator_id' => ParameterType::INTEGER,
			],
		);
	}

	private function setEnv(string $name, string $value): void
	{
		if (!isset($this->originalEnv[$name])) {
			$this->originalEnv[$name] = [
				'env' => getenv($name),
				'server' => $_SERVER[$name] ?? null,
				'hasServer' => array_key_exists($name, $_SERVER),
				'environment' => $_ENV[$name] ?? null,
				'hasEnvironment' => array_key_exists($name, $_ENV),
			];
		}
		putenv($name.'='.$value);
		$_SERVER[$name] = $value;
		$_ENV[$name] = $value;
	}
}
