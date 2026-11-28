<?php

declare(strict_types=1);

namespace iikiti\CMS\Storage;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Local filesystem implementation of {@see AssetStorageInterface}.
 *
 * Enforces storage outside the public document root, strict key formatting,
 * `0700` directory permissions, symlink/realpath verification, and atomic writes.
 */
final class LocalAssetStorage implements AssetStorageInterface
{
	public const DRIVER_NAME = 'local';

	private const KEY_PATTERN = '~^[a-f0-9]{2}/[a-f0-9]{64}\.[a-z0-9]{1,12}$~';

	private readonly string $directory;
	private readonly string $publicDirectory;
	private readonly AssetMimeTypeRegistry $mimeRegistry;

	public function __construct(
		string $directory,
		private readonly Filesystem $filesystem = new Filesystem(),
		?string $publicDirectory = null,
		?AssetMimeTypeRegistry $mimeRegistry = null,
	) {
		$this->directory = $this->normalisePath($directory);
		$this->publicDirectory = $this->normalisePath($publicDirectory ?? dirname(__DIR__, 2).'/public');
		if ($this->isWithin($this->directory, $this->publicDirectory)) {
			throw new \InvalidArgumentException('Digital asset storage must be outside the public document root.');
		}
		$this->mimeRegistry = $mimeRegistry ?? new AssetMimeTypeRegistry([new CoreAssetMimeTypeProvider()]);
	}

	public function getDriverName(): string
	{
		return self::DRIVER_NAME;
	}

	public function storeUploadedFile(UploadedFile $file, string $mimeType): string
	{
		$extension = $this->mimeRegistry->extensionFor($mimeType);
		$key = $this->newKey($extension);
		$path = $this->pathFor($key);
		$this->filesystem->mkdir(dirname($path), 0700);
		$this->assertProtectedPath($path);
		$file->move(dirname($path), basename($path));

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
		$directory = $this->directory.'/.tmp';
		$this->filesystem->mkdir($directory, 0700);
		$path = $directory.'/'.bin2hex(random_bytes(32)).'.'.$extension;
		$this->assertProtectedPath($path);

		return $path;
	}

	public function storeTemporaryFile(string $temporaryPath, string $mimeType): string
	{
		if (!is_file($temporaryPath)) {
			throw new \InvalidArgumentException('Temporary asset file does not exist.');
		}
		$extension = $this->mimeRegistry->extensionFor($mimeType);
		$key = $this->newKey($extension);
		$target = $this->pathFor($key);
		$this->filesystem->mkdir(dirname($target), 0700);
		$this->assertProtectedPath($target);
		$this->filesystem->rename($temporaryPath, $target);

		return $key;
	}

	/**
	 * @param resource $stream
	 */
	public function writeStream(string $key, mixed $stream, ?string $mimeType = null): void
	{
		if (!is_resource($stream)) {
			throw new \InvalidArgumentException('Stream must be a valid readable resource.');
		}
		if (null !== $mimeType && !$this->mimeRegistry->isAllowed($mimeType)) {
			throw new \InvalidArgumentException('Unsupported asset file type.');
		}

		$target = $this->pathFor($key);
		$extension = pathinfo($key, PATHINFO_EXTENSION);
		$temporaryPath = $this->createTemporaryPath('' !== $extension ? $extension : 'tmp');
		$handle = @fopen($temporaryPath, 'wb');
		if (false === $handle) {
			throw new \RuntimeException('Unable to open temporary asset file for writing.');
		}

		try {
			while (!feof($stream)) {
				$chunk = fread($stream, 65536);
				if (false === $chunk) {
					throw new \RuntimeException('Unable to read from source asset stream.');
				}
				if ('' === $chunk) {
					break;
				}
				if (strlen($chunk) !== fwrite($handle, $chunk)) {
					throw new \RuntimeException('Unable to write asset stream to temporary storage.');
				}
			}
			fflush($handle);
		} catch (\Throwable $exception) {
			fclose($handle);
			$this->filesystem->remove($temporaryPath);
			throw $exception;
		}
		fclose($handle);

		try {
			$this->filesystem->mkdir(dirname($target), 0700);
			$this->assertProtectedPath($target);
			$this->filesystem->rename($temporaryPath, $target, true);
		} catch (\Throwable $exception) {
			$this->filesystem->remove($temporaryPath);
			throw $exception;
		}
	}

	/**
	 * @return resource
	 */
	public function readStream(string $key): mixed
	{
		$path = $this->pathFor($key);
		if (!is_file($path)) {
			throw new \RuntimeException('Stored asset file does not exist.');
		}
		$this->assertProtectedPath($path);
		$handle = @fopen($path, 'rb');
		if (false === $handle) {
			throw new \RuntimeException('Stored asset file could not be opened for reading.');
		}

		return $handle;
	}

	public function exists(string $key): bool
	{
		$path = $this->pathFor($key);

		return is_file($path);
	}

	public function size(string $key): ?int
	{
		$path = $this->pathFor($key);
		if (!is_file($path)) {
			return null;
		}
		$size = @filesize($path);

		return false === $size ? null : $size;
	}

	public function delete(string $key): void
	{
		$this->filesystem->remove($this->pathFor($key));
	}

	public function pathFor(string $key): string
	{
		if (1 !== preg_match(self::KEY_PATTERN, $key)) {
			throw new \InvalidArgumentException('Invalid asset storage key.');
		}

		return $this->directory.'/'.$key;
	}

	public function isLocal(): bool
	{
		return true;
	}

	public function materializeToLocalTemp(string $key, ?string $extension = null): LocalTempFile
	{
		$path = $this->pathFor($key);
		if (!is_file($path)) {
			throw new \RuntimeException('Stored asset file does not exist.');
		}
		$this->assertProtectedPath($path);

		return new LocalTempFile($path, false);
	}

	public function supportsTemporaryUrls(): bool
	{
		return false;
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function temporaryUrl(string $key, \DateTimeImmutable $expiresAt, array $options = []): ?string
	{
		$this->pathFor($key);

		return null;
	}

	public function publicUrl(string $key): ?string
	{
		$this->pathFor($key);

		return null;
	}

	private function newKey(string $extension): string
	{
		$token = bin2hex(random_bytes(32));

		return substr($token, 0, 2).'/'.$token.'.'.$extension;
	}

	private function assertProtectedPath(string $path): void
	{
		$realPath = realpath(dirname($path));
		$realPublicDirectory = realpath($this->publicDirectory);
		if (false !== $realPath && false !== $realPublicDirectory && $this->isWithin($realPath, $realPublicDirectory)) {
			throw new \InvalidArgumentException('Digital asset storage must resolve outside the public document root.');
		}
	}

	private function normalisePath(string $path): string
	{
		if (!str_starts_with($path, '/')) {
			$workingDirectory = getcwd();
			if (false === $workingDirectory) {
				throw new \RuntimeException('The current working directory could not be determined.');
			}
			$path = $workingDirectory.'/'.$path;
		}
		$segments = [];
		foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
			if ('' === $segment || '.' === $segment) {
				continue;
			}
			if ('..' === $segment) {
				array_pop($segments);
				continue;
			}
			$segments[] = $segment;
		}

		return '/'.implode('/', $segments);
	}

	private function isWithin(string $path, string $directory): bool
	{
		$path = rtrim($path, '/');
		$directory = rtrim($directory, '/');

		return $path === $directory || str_starts_with($path, $directory.'/');
	}
}
