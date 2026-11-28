<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Service\AssetStreamResponseFactory;
use iikiti\CMS\Storage\AssetStorageInterface;
use iikiti\CMS\Storage\LocalAssetStorage;
use iikiti\CMS\Storage\LocalTempFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AssetStreamResponseFactoryTest extends TestCase
{
	private string $directory;
	private Filesystem $filesystem;

	protected function setUp(): void
	{
		$this->directory = dirname(__DIR__, 2).'/var/test-stream-factory-'.bin2hex(random_bytes(8));
		$this->filesystem = new Filesystem();
		$this->filesystem->mkdir($this->directory, 0700);
	}

	protected function tearDown(): void
	{
		$this->filesystem->remove($this->directory);
	}

	public function testLocalResponseSetsStrongEtagImmutableCacheControlAndHandles304(): void
	{
		$storage = new LocalAssetStorage($this->directory, $this->filesystem);
		$key = 'ab/'.str_repeat('a', 64).'.png';
		$content = '0123456789abcdef';
		$checksum = hash('sha256', $content);

		$stream = fopen('php://memory', 'r+b');
		self::assertIsResource($stream);
		fwrite($stream, $content);
		rewind($stream);
		$storage->writeStream($key, $stream, 'image/png');
		fclose($stream);

		$factory = new AssetStreamResponseFactory($storage);

		// Public versioned request -> immutable
		$versionedRequest = Request::create('/assets/test/original?v='.substr($checksum, 0, 12));
		$response = $factory->createResponse($versionedRequest, $key, 'image/png', 'logo.png', 'public', $checksum, strlen($content));

		self::assertSame(Response::HTTP_OK, $response->getStatusCode());
		self::assertSame('"'.$checksum.'"', $response->headers->get('ETag'));
		self::assertSame('bytes', $response->headers->get('Accept-Ranges'));
		self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
		self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
		self::assertStringContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));
		self::assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
		self::assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));

		// Conditional If-None-Match -> 304 Not Modified
		$conditionalRequest = Request::create('/assets/test/original', 'GET', [], [], [], ['HTTP_IF_NONE_MATCH' => '"'.$checksum.'"']);
		$notModified = $factory->createResponse($conditionalRequest, $key, 'image/png', 'logo.png', 'public', $checksum, strlen($content));

		self::assertSame(Response::HTTP_NOT_MODIFIED, $notModified->getStatusCode());
		self::assertSame('"'.$checksum.'"', $notModified->headers->get('ETag'));
		self::assertStringContainsString('stale-while-revalidate=86400', (string) $notModified->headers->get('Cache-Control'));
	}

	public function testRemoteStorageSupportsByteRanges416AndHeadWithoutReadingBody(): void
	{
		$payload = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$checksum = hash('sha256', $payload);
		$storage = $this->createInMemoryRemoteStorage(['ab/'.str_repeat('b', 64).'.txt' => $payload]);
		$factory = new AssetStreamResponseFactory($storage);
		$key = 'ab/'.str_repeat('b', 64).'.txt';

		// 206 Partial Content
		$rangeRequest = Request::create('/assets/test/original', 'GET', [], [], [], ['HTTP_RANGE' => 'bytes=2-5']);
		$partial = $factory->createResponse($rangeRequest, $key, 'text/plain', 'doc.txt', 'private', $checksum, strlen($payload));

		self::assertInstanceOf(StreamedResponse::class, $partial);
		self::assertSame(Response::HTTP_PARTIAL_CONTENT, $partial->getStatusCode());
		self::assertSame('bytes 2-5/26', $partial->headers->get('Content-Range'));
		self::assertSame('4', $partial->headers->get('Content-Length'));
		self::assertSame('bytes', $partial->headers->get('Accept-Ranges'));
		self::assertStringContainsString('private', (string) $partial->headers->get('Cache-Control'));
		self::assertStringContainsString('no-store', (string) $partial->headers->get('Cache-Control'));
		self::assertStringStartsWith('attachment;', (string) $partial->headers->get('Content-Disposition'));

		ob_start();
		$partial->sendContent();
		$body = (string) ob_get_clean();
		self::assertSame('CDEF', $body);

		// 416 Range Not Satisfiable
		$invalidRangeRequest = Request::create('/assets/test/original', 'GET', [], [], [], ['HTTP_RANGE' => 'bytes=100-200']);
		$unsatisfiable = $factory->createResponse($invalidRangeRequest, $key, 'text/plain', 'doc.txt', 'private', $checksum, strlen($payload));

		self::assertSame(Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, $unsatisfiable->getStatusCode());
		self::assertSame('bytes */26', $unsatisfiable->headers->get('Content-Range'));

		// HEAD request returns empty body and full metadata
		$headRequest = Request::create('/assets/test/original', 'HEAD');
		$headResponse = $factory->createResponse($headRequest, $key, 'text/plain', 'doc.txt', 'public', $checksum, strlen($payload));

		self::assertSame(Response::HTTP_OK, $headResponse->getStatusCode());
		self::assertSame('', $headResponse->getContent());
		self::assertSame('26', $headResponse->headers->get('Content-Length'));
		self::assertSame('"'.$checksum.'"', $headResponse->headers->get('ETag'));
	}

	public function testRedirectModeReturnsTemporaryUrlOrCdnRedirect(): void
	{
		$key = 'cd/'.str_repeat('c', 64).'.jpg';
		$storage = $this->createInMemoryRemoteStorage([$key => 'jpeg-bytes'], supportsTemporaryUrls: true);

		$cdnFactory = new AssetStreamResponseFactory($storage, null, 'redirect', 'https://cdn.example.test/assets');
		$publicRedirect = $cdnFactory->createResponse(Request::create('/assets/test/original'), $key, 'image/jpeg', 'photo.jpg', 'public');

		self::assertInstanceOf(RedirectResponse::class, $publicRedirect);
		self::assertSame(Response::HTTP_FOUND, $publicRedirect->getStatusCode());
		self::assertSame('https://cdn.example.test/assets/'.$key, $publicRedirect->getTargetUrl());
		self::assertStringContainsString('no-store', (string) $publicRedirect->headers->get('Cache-Control'));

		$privateRedirect = $cdnFactory->createResponse(Request::create('/assets/test/original'), $key, 'image/jpeg', 'photo.jpg', 'private');

		self::assertInstanceOf(RedirectResponse::class, $privateRedirect);
		self::assertSame(Response::HTTP_FOUND, $privateRedirect->getStatusCode());
		self::assertStringStartsWith('https://storage.example.test/presigned/', $privateRedirect->getTargetUrl());
		self::assertStringContainsString('no-store', (string) $privateRedirect->headers->get('Cache-Control'));
	}

	/**
	 * @param array<string,string> $objects
	 */
	private function createInMemoryRemoteStorage(array $objects, bool $supportsTemporaryUrls = false): AssetStorageInterface
	{
		return new class ($objects, $supportsTemporaryUrls) implements AssetStorageInterface {
			/** @param array<string,string> $objects */
			public function __construct(
				private array $objects,
				private readonly bool $supportsTemporaryUrls,
			) {
			}

			public function getDriverName(): string
			{
				return 'memory';
			}

			public function storeUploadedFile(UploadedFile $file, string $mimeType): string
			{
				throw new \LogicException('Not used in test.');
			}

			public function createTemporaryPath(string $mimeType): string
			{
				throw new \LogicException('Not used in test.');
			}

			public function storeTemporaryFile(string $temporaryPath, string $mimeType): string
			{
				throw new \LogicException('Not used in test.');
			}

			public function writeStream(string $key, mixed $stream, ?string $mimeType = null): void
			{
				if (!is_resource($stream)) {
					throw new \InvalidArgumentException('Invalid stream.');
				}
				$this->objects[$key] = (string) stream_get_contents($stream);
			}

			public function readStream(string $key): mixed
			{
				$handle = fopen('php://memory', 'r+b');
				if (false === $handle) {
					throw new \RuntimeException('Failed to open memory stream.');
				}
				fwrite($handle, $this->objects[$key] ?? '');
				rewind($handle);

				return $handle;
			}

			public function exists(string $key): bool
			{
				return array_key_exists($key, $this->objects);
			}

			public function size(string $key): ?int
			{
				return array_key_exists($key, $this->objects) ? strlen($this->objects[$key]) : null;
			}

			public function delete(string $key): void
			{
				unset($this->objects[$key]);
			}

			public function pathFor(string $key): string
			{
				throw new \LogicException('Remote storage does not provide local paths.');
			}

			public function isLocal(): bool
			{
				return false;
			}

			public function materializeToLocalTemp(string $key, ?string $extension = null): LocalTempFile
			{
				throw new \LogicException('Not used in test.');
			}

			public function supportsTemporaryUrls(): bool
			{
				return $this->supportsTemporaryUrls;
			}

			public function temporaryUrl(string $key, \DateTimeImmutable $expiresAt, array $options = []): ?string
			{
				return $this->supportsTemporaryUrls ? 'https://storage.example.test/presigned/'.$key.'?exp='.$expiresAt->getTimestamp() : null;
			}

			public function publicUrl(string $key): ?string
			{
				return null;
			}
		};
	}
}
