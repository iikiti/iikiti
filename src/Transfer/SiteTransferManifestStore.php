<?php

declare(strict_types=1);

namespace iikiti\CMS\Transfer;

use Symfony\Component\Filesystem\Filesystem;

final class SiteTransferManifestStore
{
	public function __construct(private readonly string $directory, private readonly Filesystem $filesystem = new Filesystem())
	{
		$this->filesystem->mkdir($directory, 0700);
		@chmod($directory, 0700);
	}

	/** @param array<string,mixed> $header
	 *  @param array<string,mixed> $record
	 */
	public function append(int|string $exportId, array $header, array $record): void
	{
		$path = $this->pathFor($exportId);
		$this->filesystem->mkdir(dirname($path), 0700);
		$handle = fopen($path, 'c+b');
		if (false === $handle) {
			throw new \RuntimeException('Unable to open the site export manifest.');
		}
		@chmod($path, 0600);
		try {
			if (!flock($handle, LOCK_EX)) {
				throw new \RuntimeException('Unable to lock the site export manifest.');
			}
			fseek($handle, 0, SEEK_END);
			if (0 === ftell($handle)) {
				fwrite($handle, json_encode(['kind' => 'manifest', ...$header], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
			}
			if (false === fwrite($handle, json_encode(['kind' => 'record', ...$record], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n")) {
				throw new \RuntimeException('Unable to append to the site export manifest.');
			}
			fflush($handle);
			flock($handle, LOCK_UN);
		} finally {
			fclose($handle);
		}
	}

	/** @param array<string,mixed> $header */
	public function ensureHeader(int|string $exportId, array $header): void
	{
		$path = $this->pathFor($exportId);
		$this->filesystem->mkdir(dirname($path), 0700);
		if (!is_file($path) || 0 === filesize($path)) {
			file_put_contents($path, json_encode(['kind' => 'manifest', ...$header], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX);
			@chmod($path, 0600);
		}
	}

	public function pathFor(int|string $exportId): string
	{
		$id = (string) $exportId;
		if (!ctype_digit($id)) {
			throw new \InvalidArgumentException('Invalid transfer export ID.');
		}

		return rtrim($this->directory, '/').'/'.$id.'.ndjson';
	}
}
