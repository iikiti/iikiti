<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AssetStorage
{
	private const EXTENSIONS = [
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/gif' => 'gif',
		'image/webp' => 'webp',
		'application/pdf' => 'pdf',
		'text/plain' => 'txt',
		'text/markdown' => 'md',
		'text/csv' => 'csv',
		'application/json' => 'json',
		'application/xml' => 'xml',
		'text/xml' => 'xml',
	];

	private readonly string $directory;
	private readonly string $publicDirectory;

	public function __construct(string $directory, private readonly Filesystem $filesystem = new Filesystem(), ?string $publicDirectory = null)
	{
		$this->directory = $this->normalisePath($directory);
		$this->publicDirectory = $this->normalisePath($publicDirectory ?? dirname(__DIR__, 2).'/public');
		if ($this->isWithin($this->directory, $this->publicDirectory)) {
			throw new \InvalidArgumentException('Digital asset storage must be outside the public document root.');
		}
	}

	public function storeUploadedFile(UploadedFile $file, string $mimeType): string
	{
		$extension = self::EXTENSIONS[$mimeType] ?? throw new \InvalidArgumentException('Unsupported asset file type.');
		$key = $this->newKey($extension);
		$path = $this->pathFor($key);
		$this->filesystem->mkdir(dirname($path), 0700);
		$this->assertProtectedPath($path);
		$file->move(dirname($path), basename($path));

		return $key;
	}

	public function createTemporaryPath(string $extension): string
	{
		if (!preg_match('/^[a-z0-9]{1,8}$/', $extension)) {
			throw new \InvalidArgumentException('Invalid temporary file extension.');
		}
		$directory = $this->directory.'/.tmp';
		$this->filesystem->mkdir($directory, 0700);
		$path = $directory.'/'.bin2hex(random_bytes(32)).'.'.$extension;
		$this->assertProtectedPath($path);

		return $path;
	}

	public function storeTemporaryFile(string $path, string $mimeType): string
	{
		if (!is_file($path)) {
			throw new \InvalidArgumentException('Temporary asset file does not exist.');
		}
		$extension = self::EXTENSIONS[$mimeType] ?? throw new \InvalidArgumentException('Unsupported asset file type.');
		$key = $this->newKey($extension);
		$target = $this->pathFor($key);
		$this->filesystem->mkdir(dirname($target), 0700);
		$this->assertProtectedPath($target);
		$this->filesystem->rename($path, $target);

		return $key;
	}

	public function pathFor(string $key): string
	{
		if (!preg_match('~^[a-f0-9]{2}/[a-f0-9]{64}\.[a-z0-9]{1,8}$~', $key)) {
			throw new \InvalidArgumentException('Invalid asset storage key.');
		}

		return $this->directory.'/'.$key;
	}

	public function delete(?string $key): void
	{
		if (null === $key) {
			return;
		}
		$this->filesystem->remove($this->pathFor($key));
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
