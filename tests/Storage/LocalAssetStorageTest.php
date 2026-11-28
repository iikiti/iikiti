<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Storage;

use iikiti\CMS\Storage\AssetMimeTypeDefinition;
use iikiti\CMS\Storage\AssetMimeTypeProviderInterface;
use iikiti\CMS\Storage\AssetMimeTypeRegistry;
use iikiti\CMS\Storage\AssetStorageRegistry;
use iikiti\CMS\Storage\CoreAssetMimeTypeProvider;
use iikiti\CMS\Storage\DelegatingAssetStorage;
use iikiti\CMS\Storage\LocalAssetStorage;
use iikiti\CMS\Storage\LocalAssetStorageProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LocalAssetStorageTest extends TestCase
{
	private string $directory;
	private Filesystem $filesystem;

	protected function setUp(): void
	{
		$this->directory = dirname(__DIR__, 2).'/var/test-local-storage-'.bin2hex(random_bytes(8));
		$this->filesystem = new Filesystem();
		$this->filesystem->mkdir($this->directory, 0700);
	}

	protected function tearDown(): void
	{
		$this->filesystem->remove($this->directory);
	}

	public function testWritesReadsMeasuresMaterializesAndDeletesObjects(): void
	{
		$storage = new LocalAssetStorage($this->directory, $this->filesystem);
		$key = 'ab/'.str_repeat('1', 64).'.txt';

		$source = fopen('php://memory', 'r+b');
		self::assertIsResource($source);
		fwrite($source, 'hello pluggable storage');
		rewind($source);

		$storage->writeStream($key, $source, 'text/plain');
		fclose($source);

		self::assertSame('local', $storage->getDriverName());
		self::assertTrue($storage->isLocal());
		self::assertFalse($storage->supportsTemporaryUrls());
		self::assertNull($storage->temporaryUrl($key, new \DateTimeImmutable('+5 minutes')));
		self::assertNull($storage->publicUrl($key));
		self::assertTrue($storage->exists($key));
		self::assertSame(23, $storage->size($key));

		$readHandle = $storage->readStream($key);
		self::assertIsResource($readHandle);
		self::assertSame('hello pluggable storage', stream_get_contents($readHandle));
		fclose($readHandle);

		$materialized = $storage->materializeToLocalTemp($key);
		self::assertFalse($materialized->isTemporaryCopy);
		self::assertSame($storage->pathFor($key), $materialized->path);
		$materialized->cleanup();
		self::assertTrue($storage->exists($key));

		$storage->delete($key);
		self::assertFalse($storage->exists($key));
		self::assertNull($storage->size($key));
	}

	public function testStoreTemporaryFileSupportsMimeTypesAndCustomPluginExtensions(): void
	{
		$customProvider = new class () implements AssetMimeTypeProviderInterface {
			public function getDefinitions(): array
			{
				return [
					new AssetMimeTypeDefinition('video/mp4', 'mp4', 'video', 104857600, false),
				];
			}
		};
		$registry = new AssetMimeTypeRegistry([new CoreAssetMimeTypeProvider(), $customProvider]);
		$storage = new LocalAssetStorage($this->directory, $this->filesystem, null, $registry);

		$tempPath = $storage->createTemporaryPath('video/mp4');
		self::assertStringEndsWith('.mp4', $tempPath);
		file_put_contents($tempPath, 'fake-mp4-data');

		$key = $storage->storeTemporaryFile($tempPath, 'video/mp4');
		self::assertMatchesRegularExpression('~^[a-f0-9]{2}/[a-f0-9]{64}\.mp4$~', $key);
		self::assertFalse(is_file($tempPath));
		self::assertTrue($storage->exists($key));
	}

	public function testRejectsStorageInsidePublicRoot(): void
	{
		$publicDirectory = dirname(__DIR__, 2).'/public';

		$this->expectException(\InvalidArgumentException::class);
		new LocalAssetStorage($publicDirectory.'/uploads', $this->filesystem, $publicDirectory);
	}

	public function testRejectsInvalidOrTraversalKeys(): void
	{
		$storage = new LocalAssetStorage($this->directory, $this->filesystem);

		$this->expectException(\InvalidArgumentException::class);
		$storage->pathFor('../escape.txt');
	}

	public function testRegistryAndDelegatingStorageResolveActiveLocalDriver(): void
	{
		$local = new LocalAssetStorage($this->directory, $this->filesystem);
		$registry = new AssetStorageRegistry([new LocalAssetStorageProvider($local)], 'local');
		$delegating = new DelegatingAssetStorage($registry);

		self::assertSame(['local'], $registry->getDrivers());
		self::assertSame('local', $delegating->getDriverName());
		self::assertTrue($delegating->isLocal());
	}
}
