<?php

declare(strict_types=1);

namespace iikiti\CMS\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Pluggable storage abstraction for binary digital asset originals and variants.
 */
interface AssetStorageInterface
{
	public function getDriverName(): string;

	public function storeUploadedFile(UploadedFile $file, string $mimeType): string;

	public function createTemporaryPath(string $mimeType): string;

	public function storeTemporaryFile(string $temporaryPath, string $mimeType): string;

	/**
	 * @param resource $stream
	 */
	public function writeStream(string $key, mixed $stream, ?string $mimeType = null): void;

	/**
	 * Returns an open readable PHP stream resource for the stored object.
	 *
	 * @return resource
	 */
	public function readStream(string $key): mixed;

	public function exists(string $key): bool;

	public function size(string $key): ?int;

	public function delete(string $key): void;

	/**
	 * Returns the local filesystem path for local drivers; remote drivers may
	 * throw {@see \LogicException} and callers should prefer {@see readStream()}
	 * or {@see materializeToLocalTemp()}.
	 */
	public function pathFor(string $key): string;

	public function isLocal(): bool;

	public function materializeToLocalTemp(string $key, ?string $extension = null): LocalTempFile;

	public function supportsTemporaryUrls(): bool;

	/**
	 * @param array<string,mixed> $options
	 */
	public function temporaryUrl(string $key, \DateTimeImmutable $expiresAt, array $options = []): ?string;

	public function publicUrl(string $key): ?string;
}
