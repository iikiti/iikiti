<?php

declare(strict_types=1);

namespace iikiti\CMS\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Default {@see AssetStorageInterface} service delegating all calls to the
 * currently active storage backend resolved by {@see AssetStorageRegistry}.
 */
final readonly class DelegatingAssetStorage implements AssetStorageInterface
{
	public function __construct(
		private AssetStorageRegistry $registry,
	) {
	}

	public function getDriverName(): string
	{
		return $this->registry->getActiveStorage()->getDriverName();
	}

	public function storeUploadedFile(UploadedFile $file, string $mimeType): string
	{
		return $this->registry->getActiveStorage()->storeUploadedFile($file, $mimeType);
	}

	public function createTemporaryPath(string $mimeType): string
	{
		return $this->registry->getActiveStorage()->createTemporaryPath($mimeType);
	}

	public function storeTemporaryFile(string $temporaryPath, string $mimeType): string
	{
		return $this->registry->getActiveStorage()->storeTemporaryFile($temporaryPath, $mimeType);
	}

	/**
	 * @param resource $stream
	 */
	public function writeStream(string $key, mixed $stream, ?string $mimeType = null): void
	{
		$this->registry->getActiveStorage()->writeStream($key, $stream, $mimeType);
	}

	/**
	 * @return resource
	 */
	public function readStream(string $key): mixed
	{
		return $this->registry->getActiveStorage()->readStream($key);
	}

	public function exists(string $key): bool
	{
		return $this->registry->getActiveStorage()->exists($key);
	}

	public function size(string $key): ?int
	{
		return $this->registry->getActiveStorage()->size($key);
	}

	public function delete(string $key): void
	{
		$this->registry->getActiveStorage()->delete($key);
	}

	public function pathFor(string $key): string
	{
		return $this->registry->getActiveStorage()->pathFor($key);
	}

	public function isLocal(): bool
	{
		return $this->registry->getActiveStorage()->isLocal();
	}

	public function materializeToLocalTemp(string $key, ?string $extension = null): LocalTempFile
	{
		return $this->registry->getActiveStorage()->materializeToLocalTemp($key, $extension);
	}

	public function supportsTemporaryUrls(): bool
	{
		return $this->registry->getActiveStorage()->supportsTemporaryUrls();
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function temporaryUrl(string $key, \DateTimeImmutable $expiresAt, array $options = []): ?string
	{
		return $this->registry->getActiveStorage()->temporaryUrl($key, $expiresAt, $options);
	}

	public function publicUrl(string $key): ?string
	{
		return $this->registry->getActiveStorage()->publicUrl($key);
	}
}
