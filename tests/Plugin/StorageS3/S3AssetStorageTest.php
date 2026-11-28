<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Plugin\StorageS3;

use iikiti\Extension\StorageS3\Service\AwsSigV4Signer;
use iikiti\Extension\StorageS3\Service\S3StorageConfig;
use iikiti\Extension\StorageS3\Storage\FlysystemAdapterInterface;
use iikiti\Extension\StorageS3\Storage\FlysystemBridgeStorage;
use iikiti\Extension\StorageS3\Storage\S3AssetStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class S3AssetStorageTest extends TestCase
{
	private string $cacheDir;
	private Filesystem $filesystem;

	protected function setUp(): void
	{
		$this->cacheDir = dirname(__DIR__, 3).'/var/test-s3-storage-'.bin2hex(random_bytes(8));
		$this->filesystem = new Filesystem();
		$this->filesystem->mkdir($this->cacheDir, 0700);
	}

	protected function tearDown(): void
	{
		$this->filesystem->remove($this->cacheDir);
	}

	public function testPutGetHeadDeleteMaterializeAndUrlsWithMockHttpClient(): void
	{
		$key = 'ab/'.str_repeat('1', 64).'.txt';
		/** @var array<string,array{body:string,contentType:string}> $objects */
		$objects = [];
		/** @var list<array{method:string,url:string,headers:array<array-key,mixed>}> $recorded */
		$recorded = [];

		$httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$objects, &$recorded): MockResponse {
			$recorded[] = [
				'method' => $method,
				'url' => $url,
				'headers' => is_array($options['headers'] ?? null) ? $options['headers'] : [],
				'max_redirects' => $options['max_redirects'] ?? null,
				'timeout' => $options['timeout'] ?? null,
			];

			if ('PUT' === $method) {
				$body = is_string($options['body'] ?? null) ? $options['body'] : '';
				$objects[$url] = ['body' => $body, 'contentType' => 'text/plain'];

				return new MockResponse('', ['http_code' => 200]);
			}

			if ('HEAD' === $method) {
				if (!isset($objects[$url])) {
					return new MockResponse('', ['http_code' => 404]);
				}

				return new MockResponse('', [
					'http_code' => 200,
					'response_headers' => [
						'Content-Length: '.strlen($objects[$url]['body']),
						'Content-Type: '.$objects[$url]['contentType'],
					],
				]);
			}

			if ('GET' === $method) {
				if (!isset($objects[$url])) {
					return new MockResponse('', ['http_code' => 404]);
				}

				return new MockResponse($objects[$url]['body'], ['http_code' => 200]);
			}

			if ('DELETE' === $method) {
				unset($objects[$url]);

				return new MockResponse('', ['http_code' => 204]);
			}

			return new MockResponse('', ['http_code' => 405]);
		});

		$config = new S3StorageConfig(
			provider: S3StorageConfig::PROVIDER_S3,
			endpoint: 'http://minio.local:9000',
			region: 'us-east-1',
			bucket: 'cms-assets',
			accessKey: 'AKIAEXAMPLE',
			secretKey: 'SECRETEXAMPLE',
			prefix: 'tenant-a',
			pathStyle: true,
			cdnUrl: 'https://cdn.example.test/assets',
			allowPrivateEndpoints: true,
		);

		$storage = new S3AssetStorage(
			config: $config,
			httpClient: $httpClient,
			signer: new AwsSigV4Signer(),
			cacheDirectory: $this->cacheDir,
		);

		self::assertSame('s3', $storage->getDriverName());
		self::assertFalse($storage->isLocal());
		self::assertTrue($storage->supportsTemporaryUrls());
		self::assertFalse($storage->exists($key));
		self::assertNull($storage->size($key));

		$source = fopen('php://memory', 'r+b');
		self::assertIsResource($source);
		fwrite($source, 'remote s3 payload');
		rewind($source);
		$storage->writeStream($key, $source, 'text/plain');
		fclose($source);

		$expectedUrl = 'http://minio.local:9000/cms-assets/tenant-a/'.$key;
		self::assertArrayHasKey($expectedUrl, $objects);
		self::assertSame('remote s3 payload', $objects[$expectedUrl]['body']);

		self::assertTrue($storage->exists($key));
		self::assertSame(17, $storage->size($key));

		$readHandle = $storage->readStream($key);
		self::assertIsResource($readHandle);
		self::assertSame('remote s3 payload', stream_get_contents($readHandle));
		fclose($readHandle);

		$materialized = $storage->materializeToLocalTemp($key);
		self::assertTrue($materialized->isTemporaryCopy);
		self::assertFileExists($materialized->path);
		self::assertSame('remote s3 payload', file_get_contents($materialized->path));
		$materialized->cleanup();
		self::assertFileDoesNotExist($materialized->path);

		$temporaryUrl = $storage->temporaryUrl($key, new \DateTimeImmutable('+5 minutes'), [
			'mimeType' => 'text/plain',
			'disposition' => 'inline',
			'filename' => 'hello.txt',
		]);
		self::assertNotNull($temporaryUrl);
		self::assertStringStartsWith($expectedUrl.'?', $temporaryUrl);
		self::assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $temporaryUrl);
		self::assertStringContainsString('response-content-type=text%2Fplain', $temporaryUrl);
		self::assertStringContainsString('response-content-disposition=inline%3B%20filename%3D%22hello.txt%22', $temporaryUrl);

		self::assertSame('https://cdn.example.test/assets/tenant-a/'.$key, $storage->publicUrl($key));

		$storage->delete($key);
		self::assertFalse($storage->exists($key));

		// Verify Authorization header was signed and every request disabled redirects with explicit timeout.
		foreach ($recorded as $call) {
			self::assertSame(0, $call['max_redirects']);
			self::assertSame(30.0, $call['timeout']);
		}
		$putCalls = array_values(array_filter($recorded, static fn (array $call): bool => 'PUT' === $call['method']));
		self::assertCount(1, $putCalls);
		self::assertTrue(self::hasHeaderPrefix($putCalls[0]['headers'], 'Authorization', 'AWS4-HMAC-SHA256 '));
		self::assertTrue(self::hasHeaderPrefix($putCalls[0]['headers'], 'X-Amz-Content-Sha256', hash('sha256', 'remote s3 payload')));
	}

	public function testRejectsPrivateLoopbackAndInternalEndpointsByDefault(): void
	{
		foreach ([
			'http://127.0.0.1:9000',
			'http://169.254.169.254/latest/meta-data',
			'http://10.0.0.5:9000',
			'http://[::1]:9000',
			'http://localhost:9000',
			'http://minio.local:9000',
			'http://metadata.google.internal',
		] as $blocked) {
			try {
				new S3StorageConfig(endpoint: $blocked, allowPrivateEndpoints: false);
				self::fail(sprintf('Expected SSRF validation error for endpoint "%s".', $blocked));
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}

		// Public endpoint succeeds.
		$public = new S3StorageConfig(endpoint: 'https://s3.us-east-1.amazonaws.com', allowPrivateEndpoints: false);
		self::assertSame('https://s3.us-east-1.amazonaws.com', $public->endpoint);
	}

	public function testPathForThrowsLogicExceptionAndRejectsInvalidKeys(): void
	{
		$storage = new S3AssetStorage(
			config: new S3StorageConfig(
				endpoint: 'https://s3.us-east-1.amazonaws.com',
				bucket: 'cms-assets',
				accessKey: 'AKIAEXAMPLE',
				secretKey: 'SECRETEXAMPLE',
			),
			httpClient: new MockHttpClient(),
			cacheDirectory: $this->cacheDir,
		);

		$validKey = 'cd/'.str_repeat('2', 64).'.png';
		try {
			$storage->pathFor($validKey);
			self::fail('Expected LogicException for remote storage pathFor().');
		} catch (\LogicException $exception) {
			self::assertStringContainsString('materializeToLocalTemp', $exception->getMessage());
		}

		$this->expectException(\InvalidArgumentException::class);
		$storage->exists('../escape.txt');
	}

	public function testProviderPresetsForR2AndGcsResolveEndpointsAndPathStyle(): void
	{
		$r2 = (new S3StorageConfig(
			provider: S3StorageConfig::PROVIDER_S3,
			endpoint: 'https://abc123.r2.cloudflarestorage.com',
			region: 'us-east-1',
			bucket: 'r2-bucket',
			accessKey: 'r2-key',
			secretKey: 'r2-secret',
		))->withProviderPreset(S3StorageConfig::PROVIDER_R2);

		self::assertSame('r2', $r2->provider);
		self::assertSame('auto', $r2->resolvedRegion());
		self::assertTrue($r2->pathStyle);
		self::assertSame(
			'https://abc123.r2.cloudflarestorage.com/r2-bucket/ab/'.str_repeat('a', 64).'.jpg',
			$r2->buildObjectUrl('ab/'.str_repeat('a', 64).'.jpg'),
		);

		$gcs = (new S3StorageConfig(
			provider: S3StorageConfig::PROVIDER_S3,
			endpoint: '',
			region: '',
			bucket: 'gcs-bucket',
			accessKey: 'GOOG1EKEY',
			secretKey: 'gcs-secret',
		))->withProviderPreset(S3StorageConfig::PROVIDER_GCS);

		self::assertSame('gcs', $gcs->provider);
		self::assertSame('https://storage.googleapis.com', $gcs->resolvedEndpoint());
		self::assertTrue($gcs->pathStyle);
		self::assertSame(
			'https://storage.googleapis.com/gcs-bucket/ab/'.str_repeat('b', 64).'.pdf',
			$gcs->buildObjectUrl('ab/'.str_repeat('b', 64).'.pdf'),
		);
	}

	public function testFlysystemBridgeStorageDelegatesOperationsSafely(): void
	{
		$adapter = new class () implements FlysystemAdapterInterface {
			/** @var array<string,string> */
			private array $store = [];

			public function writeStream(string $location, mixed $contents, array $config = []): void
			{
				$this->store[$location] = (string) stream_get_contents($contents);
			}

			public function readStream(string $location): mixed
			{
				$stream = fopen('php://memory', 'r+b');
				if (false === $stream) {
					throw new \RuntimeException('Unable to open memory stream.');
				}
				fwrite($stream, $this->store[$location] ?? '');
				rewind($stream);

				return $stream;
			}

			public function fileExists(string $location): bool
			{
				return isset($this->store[$location]);
			}

			public function fileSize(string $location): int
			{
				return strlen($this->store[$location] ?? '');
			}

			public function delete(string $location): void
			{
				unset($this->store[$location]);
			}

			public function temporaryUrl(string $location, \DateTimeInterface $expiresAt, array $config = []): ?string
			{
				return 'https://signed.example.test/'.$location;
			}

			public function publicUrl(string $location, array $config = []): ?string
			{
				return 'https://public.example.test/'.$location;
			}
		};

		$bridge = new FlysystemBridgeStorage(
			adapter: $adapter,
			driverName: 'flysystem',
			prefix: 'media',
			supportsTemporaryUrls: true,
			cacheDirectory: $this->cacheDir,
		);

		$key = 'ef/'.str_repeat('3', 64).'.txt';
		$stream = fopen('php://memory', 'r+b');
		self::assertIsResource($stream);
		fwrite($stream, 'flysystem content');
		rewind($stream);

		$bridge->writeStream($key, $stream, 'text/plain');
		fclose($stream);

		self::assertTrue($bridge->exists($key));
		self::assertSame(17, $bridge->size($key));
		self::assertSame('https://public.example.test/media/'.$key, $bridge->publicUrl($key));
		self::assertSame('https://signed.example.test/media/'.$key, $bridge->temporaryUrl($key, new \DateTimeImmutable('+5 minutes')));

		$temp = $bridge->materializeToLocalTemp($key);
		self::assertTrue($temp->isTemporaryCopy);
		self::assertSame('flysystem content', file_get_contents($temp->path));
		$temp->cleanup();
		self::assertFileDoesNotExist($temp->path);

		$bridge->delete($key);
		self::assertFalse($bridge->exists($key));
	}

	/**
	 * @param array<array-key,mixed> $headers
	 */
	private static function hasHeaderPrefix(array $headers, string $name, string $prefix): bool
	{
		foreach ($headers as $key => $value) {
			if (is_string($key) && 0 === strcasecmp($name, $key) && is_string($value) && str_starts_with($value, $prefix)) {
				return true;
			}
			if (is_string($value) && 0 === stripos($value, $name.':')) {
				$headerVal = trim(substr($value, strlen($name) + 1));
				if (str_starts_with($headerVal, $prefix)) {
					return true;
				}
			}
		}

		return false;
	}
}
