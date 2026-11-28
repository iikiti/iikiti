<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Storage\AssetMimeTypeRegistry;
use iikiti\CMS\Storage\AssetStorageInterface;
use iikiti\CMS\Storage\LocalAssetStorage;
use iikiti\CMS\Storage\LocalTempFile;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Backward-compatible wrapper around {@see LocalAssetStorage}.
 *
 * New services should depend on {@see AssetStorageInterface} instead.
 */
final class AssetStorage implements AssetStorageInterface
{
	private readonly LocalAssetStorage $local;

	public function __construct(
		string $directory,
		Filesystem $filesystem = new Filesystem(),
		?string $publicDirectory = null,
		?AssetMimeTypeRegistry $mimeRegistry = null,
	) {
		$this->local = new LocalAssetStorage($directory, $filesystem, $publicDirectory, $mimeRegistry);
	}

	public function getDriverName(): string
	{
		return $this->local->getDriverName();
	}

	public function storeUploadedFile(UploadedFile $file, string $mimeType): string
	{
		return $this->local->storeUploadedFile($file, $mimeType);
	}

	public function createTemporaryPath(string $mimeType): string
	{
		return $this->local->createTemporaryPath($mimeType);
	}

	public function storeTemporaryFile(string $temporaryPath, string $mimeType): string
	{
		return $this->local->storeTemporaryFile($temporaryPath, $mimeType);
	}

	/**
	 * @param resource $stream
	 */
	public function writeStream(string $key, mixed $stream, ?string $mimeType = null): void
	{
		$this->local->writeStream($key, $stream, $mimeType);
	}

	/**
	 * @return resource
	 */
	public function readStream(string $key): mixed
	{
		return $this->local->readStream($key);
	}

	public function exists(string $key): bool
	{
		return $this->local->exists($key);
	}

	public function size(string $key): ?int
	{
		return $this->local->size($key);
	}

	public function pathFor(string $key): string
	{
		return $this->local->pathFor($key);
	}

	public function delete(?string $key): void
	{
		if (null === $key) {
			return;
		}
		$this->local->delete($key);
	}

	public function isLocal(): bool
	{
		return $this->local->isLocal();
	}

	public function materializeToLocalTemp(string $key, ?string $extension = null): LocalTempFile
	{
		return $this->local->materializeToLocalTemp($key, $extension);
	}

	public function supportsTemporaryUrls(): bool
	{
		return $this->local->supportsTemporaryUrls();
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function temporaryUrl(string $key, \DateTimeImmutable $expiresAt, array $options = []): ?string
	{
		return $this->local->temporaryUrl($key, $expiresAt, $options);
	}

	public function publicUrl(string $key): ?string
	{
		return $this->local->publicUrl($key);
	}
}
