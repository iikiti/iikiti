<?php

declare(strict_types=1);

namespace iikiti\Extension\StorageS3\Storage;

/**
 * Minimal Flysystem-compatible adapter contract allowing applications that
 * optionally install `league/flysystem` (or any custom remote filesystem) to
 * bridge a `FilesystemOperator` into {@see FlysystemBridgeStorage} without
 * introducing a hard Composer dependency on Flysystem.
 */
interface FlysystemAdapterInterface
{
	/**
	 * @param resource            $contents
	 * @param array<string,mixed> $config
	 */
	public function writeStream(string $location, mixed $contents, array $config = []): void;

	/**
	 * @return resource
	 */
	public function readStream(string $location): mixed;

	public function fileExists(string $location): bool;

	public function fileSize(string $location): int;

	public function delete(string $location): void;

	/**
	 * @param array<string,mixed> $config
	 */
	public function temporaryUrl(string $location, \DateTimeInterface $expiresAt, array $config = []): ?string;

	/**
	 * @param array<string,mixed> $config
	 */
	public function publicUrl(string $location, array $config = []): ?string;
}
