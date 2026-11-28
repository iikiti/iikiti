<?php

declare(strict_types=1);

namespace iikiti\Extension\StorageS3\Storage;

use iikiti\CMS\Storage\AssetMimeTypeRegistry;
use iikiti\CMS\Storage\AssetStorageInterface;
use iikiti\CMS\Storage\CoreAssetMimeTypeProvider;
use iikiti\CMS\Storage\LocalTempFile;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Optional bridge adapting any {@see FlysystemAdapterInterface} (such as a thin
 * wrapper around `League\Flysystem\FilesystemOperator`) to iikiti's
 * {@see AssetStorageInterface} contract while enforcing strict key validation
 * and MIME allowlists.
 */
#[Exclude]
final class FlysystemBridgeStorage implements AssetStorageInterface
{
	private const KEY_PATTERN = '~^[a-f0-9]{2}/[a-f0-9]{64}\.[a-z0-9]{1,12}$~';
	private const TEMP_SUBDIR = 'asset-storage-tmp';

	private readonly string $prefix;
	private readonly string $tempDirectory;
	private readonly AssetMimeTypeRegistry $mimeRegistry;
	private readonly Filesystem $filesystem;

	public function __construct(
		private readonly FlysystemAdapterInterface $adapter,
		private readonly string $driverName = 'flysystem',
		string $prefix = '',
		private readonly bool $supportsTemporaryUrls = false,
		?string $cacheDirectory = null,
		?AssetMimeTypeRegistry $mimeRegistry = null,
		?Filesystem $filesystem = null,
	) {
		$this->prefix = trim(str_replace('\\', '/', trim($prefix)), '/');
		$baseDir = null !== $cacheDirectory && '' !== trim($cacheDirectory) ?
			rtrim($cacheDirectory, '/') :
			rtrim(sys_get_temp_dir(), '/');
		$this->tempDirectory = $baseDir.'/'.self::TEMP_SUBDIR;
		$this->mimeRegistry = $mimeRegistry ?? new AssetMimeTypeRegistry([new CoreAssetMimeTypeProvider()]);
		$this->filesystem = $filesystem ?? new Filesystem();
	}

	public function getDriverName(): string
	{
		return $this->driverName;
	}

	public function storeUploadedFile(UploadedFile $file, string $mimeType): string
	{
		$extension = $this->mimeRegistry->extensionFor($mimeType);
		$key = $this->newKey($extension);
		$handle = @fopen($file->getPathname(), 'rb');
		if (false === $handle) {
			throw new \RuntimeException('Unable to open uploaded asset file for reading.');
		}

		try {
			$this->writeStream($key, $handle, $mimeType);
		} finally {
			fclose($handle);
		}

		return $key;
	}

	public function createTemporaryPath(string $mimeType): string
	{
		$extension = str_contains($mimeType, '/') ?
			$this->mimeRegistry->extensionFor($mimeType) :
			strtolower(trim($mimeType));
		if (1 !== preg_match('/^[a-z0-9]{1,12}$/', $extension)) {
			throw new \InvalidArgumentException('Invalid temporary file extension.');
		}

		$this->filesystem->mkdir($this->tempDirectory, 0700);

		return $this->tempDirectory.'/'.bin2hex(random_bytes(32)).'.'.$extension;
	}

	public function storeTemporaryFile(string $temporaryPath, string $mimeType): string
	{
		if (!is_file($temporaryPath)) {
			throw new \InvalidArgumentException('Temporary asset file does not exist.');
		}

		$extension = $this->mimeRegistry->extensionFor($mimeType);
		$key = $this->newKey($extension);
		$handle = @fopen($temporaryPath, 'rb');
		if (false === $handle) {
			throw new \RuntimeException('Unable to open temporary asset file for reading.');
		}

		try {
			$this->writeStream($key, $handle, $mimeType);
		} finally {
			fclose($handle);
		}

		$this->filesystem->remove($temporaryPath);

		return $key;
	}

	/**
	 * @param resource $stream
	 */
	public function writeStream(string $key, mixed $stream, ?string $mimeType = null): void
	{
		$location = $this->locationFor($key);
		if (!is_resource($stream)) {
			throw new \InvalidArgumentException('Stream must be a valid readable resource.');
		}
		if (null !== $mimeType && !$this->mimeRegistry->isAllowedForInternalStream($mimeType)) {
			throw new \InvalidArgumentException('Unsupported asset file type.');
		}

		$config = null !== $mimeType ? ['ContentType' => $mimeType] : [];
		$this->adapter->writeStream($location, $stream, $config);
	}

	/**
	 * @return resource
	 */
	public function readStream(string $key): mixed
	{
		$stream = $this->adapter->readStream($this->locationFor($key));
		if (!is_resource($stream)) {
			throw new \RuntimeException('Flysystem adapter did not return a valid stream resource.');
		}

		return $stream;
	}

	public function exists(string $key): bool
	{
		return $this->adapter->fileExists($this->locationFor($key));
	}

	public function size(string $key): ?int
	{
		$location = $this->locationFor($key);
		if (!$this->adapter->fileExists($location)) {
			return null;
		}

		return $this->adapter->fileSize($location);
	}

	public function delete(string $key): void
	{
		$this->adapter->delete($this->locationFor($key));
	}

	public function pathFor(string $key): string
	{
		$this->locationFor($key);

		throw new \LogicException('Remote Flysystem asset storage does not expose local filesystem paths. Use readStream() or materializeToLocalTemp().');
	}

	public function isLocal(): bool
	{
		return false;
	}

	public function materializeToLocalTemp(string $key, ?string $extension = null): LocalTempFile
	{
		$this->locationFor($key);
		$resolvedExtension = null !== $extension && '' !== trim($extension) ?
			strtolower(ltrim(trim($extension), '.')) :
			pathinfo($key, PATHINFO_EXTENSION);
		$tempPath = $this->createTemporaryPath('' !== $resolvedExtension ? $resolvedExtension : 'tmp');

		$source = $this->readStream($key);
		$target = @fopen($tempPath, 'wb');
		if (false === $target) {
			fclose($source);
			throw new \RuntimeException('Unable to open temporary file for materialized asset.');
		}

		try {
			if (false === stream_copy_to_stream($source, $target)) {
				throw new \RuntimeException('Unable to materialize remote asset to local temporary file.');
			}
			fflush($target);
		} catch (\Throwable $exception) {
			fclose($target);
			fclose($source);
			$this->filesystem->remove($tempPath);
			throw $exception;
		}

		fclose($target);
		fclose($source);

		return new LocalTempFile($tempPath, true);
	}

	public function supportsTemporaryUrls(): bool
	{
		return $this->supportsTemporaryUrls;
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function temporaryUrl(string $key, \DateTimeImmutable $expiresAt, array $options = []): ?string
	{
		$location = $this->locationFor($key);
		if (!$this->supportsTemporaryUrls) {
			return null;
		}

		return $this->adapter->temporaryUrl($location, $expiresAt, $options);
	}

	public function publicUrl(string $key): ?string
	{
		return $this->adapter->publicUrl($this->locationFor($key));
	}

	private function locationFor(string $key): string
	{
		if (1 !== preg_match(self::KEY_PATTERN, $key)) {
			throw new \InvalidArgumentException('Invalid asset storage key.');
		}

		return '' !== $this->prefix ? $this->prefix.'/'.$key : $key;
	}

	private function newKey(string $extension): string
	{
		$token = bin2hex(random_bytes(32));

		return substr($token, 0, 2).'/'.$token.'.'.$extension;
	}
}
