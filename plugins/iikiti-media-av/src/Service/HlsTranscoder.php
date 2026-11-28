<?php

declare(strict_types=1);

namespace iikiti\Extension\MediaAv\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Storage\AssetStorageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Generates HTTP Live Streaming (HLS) VOD playlists and MPEG-TS segments via FFmpeg,
 * rewrites playlist URIs to CMS-authenticated asset delivery paths, and stores all
 * generated artifacts via {@see AssetStorageInterface} as {@see DigitalAssetVariant} rows.
 */
final class HlsTranscoder
{
	public const PLAYLIST_MIME_TYPE = 'application/vnd.apple.mpegurl';
	public const SEGMENT_MIME_TYPE = 'video/mp2t';
	public const MASTER_VARIANT_KEY = 'hls_master';

	private const SEGMENT_DURATION_SECONDS = 6;
	private const TRANSCODE_TIMEOUT_SECONDS = 600;

	/** @var array<string,string> */
	private const EXTENSION_MAP = [
		'video/mp4' => 'mp4',
		'video/webm' => 'webm',
		'audio/mpeg' => 'mp3',
		'audio/ogg' => 'ogg',
		'audio/wav' => 'wav',
		'audio/mp4' => 'm4a',
		'audio/webm' => 'weba',
	];

	public function __construct(
		private readonly AssetStorageInterface $storage,
		private readonly DigitalAssetVariantRepository $variants,
		private readonly EntityManagerInterface $entityManager,
		#[Autowire('%media_av_ffmpeg_binary%')]
		private readonly string $ffmpegBinary = 'ffmpeg',
		private readonly Filesystem $filesystem = new Filesystem(),
	) {
	}

	/**
	 * Maps a public HLS filename (e.g. `master.m3u8`, `720p.m3u8`, `720p_seg_0000.ts`)
	 * to its corresponding {@see DigitalAssetVariant} key, or null if invalid.
	 */
	public static function variantKeyForSegment(string $segment): ?string
	{
		if (1 !== preg_match('/^([a-z0-9][a-z0-9_-]{0,55})\.(m3u8|ts)$/', $segment, $matches)) {
			return null;
		}

		$stem = $matches[1];
		$extension = $matches[2];
		if ('master' === $stem && 'm3u8' === $extension) {
			return self::MASTER_VARIANT_KEY;
		}

		return 'hls_'.$stem;
	}

	/**
	 * Rewrites relative playlist and segment references inside an M3U8 manifest to
	 * authenticated `/assets/{publicToken}/hls/{segment}` URLs.
	 */
	public static function rewritePlaylist(string $playlistContent, string $publicToken): string
	{
		if (1 !== preg_match('/^[a-f0-9]{64}$/', $publicToken)) {
			throw new \InvalidArgumentException('Invalid asset public token.');
		}

		$lines = preg_split("/\r\n|\n|\r/", $playlistContent);
		if (false === $lines) {
			return $playlistContent;
		}

		$rewritten = [];
		foreach ($lines as $line) {
			$trimmed = trim($line);
			if ('' === $trimmed || str_starts_with($trimmed, '#')) {
				$rewritten[] = $line;

				continue;
			}

			$basename = basename(str_replace('\\', '/', $trimmed));
			if (null !== self::variantKeyForSegment($basename)) {
				$rewritten[] = sprintf('/assets/%s/hls/%s', $publicToken, $basename);
			} else {
				$rewritten[] = $line;
			}
		}

		return implode("\n", $rewritten);
	}

	public function transcode(AbstractDigitalAsset $asset): void
	{
		$sourceKey = $asset->getStorageKey();
		if (null === $sourceKey) {
			throw new \RuntimeException('Uploaded media asset has no stored file.');
		}

		$mimeType = strtolower(trim($asset->getMimeType()));
		$extension = self::EXTENSION_MAP[$mimeType] ?? throw new \InvalidArgumentException(sprintf('Unsupported HLS source MIME type "%s".', $mimeType));
		$publicToken = $asset->getPublicToken();
		$ffmpeg = $this->assertSafeBinary($this->ffmpegBinary);

		$localSource = $this->storage->materializeToLocalTemp($sourceKey, $extension);
		$workDir = rtrim(sys_get_temp_dir(), '/').'/iikiti-hls-'.bin2hex(random_bytes(16));
		$storedNewKeys = [];
		$flushed = false;

		try {
			$sourcePath = $localSource->path;
			if ('' === $sourcePath || str_contains($sourcePath, "\0") || !is_file($sourcePath)) {
				throw new \RuntimeException('Materialized media asset file could not be read.');
			}

			$this->filesystem->mkdir($workDir, 0700);

			$existingMetadata = $asset->getMediaMetadata();
			$metadata = is_array($existingMetadata) ? $existingMetadata : [];
			$isVideo = str_starts_with($mimeType, 'video/');
			$hasAudio = false !== ($metadata['hasAudio'] ?? true);

			/** @var array<string,array{storageKey:string,mimeType:string,size:int,checksum:string,width:?int,height:?int}> $newVariants */
			$newVariants = [];
			$renditionNames = [];
			$totalSegments = 0;

			if ($isVideo) {
				$sourceHeight = is_int($metadata['height'] ?? null) ? $metadata['height'] : 480;
				$renditions = $this->selectVideoRenditions($sourceHeight);
				$masterLines = ['#EXTM3U', '#EXT-X-VERSION:3'];

				foreach ($renditions as $rendition) {
					$name = $rendition['name'];
					$playlistFile = $workDir.'/'.$name.'.m3u8';
					$segmentPattern = $workDir.'/'.$name.'_seg_%04d.ts';

					$args = [
						$ffmpeg,
						'-nostdin',
						'-y',
						'-v', 'error',
						'-threads', '2',
						'-i', $sourcePath,
						'-vf', sprintf('scale=-2:min(%d\\,ih)', $rendition['height']),
						'-c:v', 'libx264',
						'-preset', 'veryfast',
						'-profile:v', 'main',
						'-crf', '23',
						'-maxrate', $rendition['maxrate'],
						'-bufsize', $rendition['bufsize'],
						'-pix_fmt', 'yuv420p',
						'-g', '48',
						'-keyint_min', '48',
						'-sc_threshold', '0',
					];
					if ($hasAudio) {
						array_push($args, '-c:a', 'aac', '-b:a', '128k', '-ac', '2');
					} else {
						$args[] = '-an';
					}
					array_push(
						$args,
						'-f', 'hls',
						'-hls_time', (string) self::SEGMENT_DURATION_SECONDS,
						'-hls_playlist_type', 'vod',
						'-hls_segment_filename', $segmentPattern,
						$playlistFile,
					);

					$process = new Process($args);
					$process->setTimeout(self::TRANSCODE_TIMEOUT_SECONDS);
					$process->mustRun();

					$segmentCount = $this->collectRenditionArtifacts(
						$workDir,
						$name,
						$playlistFile,
						$publicToken,
						$rendition['width'],
						$rendition['height'],
						$newVariants,
						$storedNewKeys,
					);
					$totalSegments += $segmentCount;
					$renditionNames[] = $name;

					$masterLines[] = sprintf(
						'#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d',
						$rendition['bandwidth'],
						$rendition['width'],
						$rendition['height'],
					);
					$masterLines[] = $name.'.m3u8';
				}

				$masterContent = self::rewritePlaylist(implode("\n", $masterLines)."\n", $publicToken);
			} else {
				$name = 'audio';
				$playlistFile = $workDir.'/'.$name.'.m3u8';
				$segmentPattern = $workDir.'/'.$name.'_seg_%04d.ts';

				$process = new Process([
					$ffmpeg,
					'-nostdin',
					'-y',
					'-v', 'error',
					'-threads', '2',
					'-i', $sourcePath,
					'-vn',
					'-c:a', 'aac',
					'-b:a', '128k',
					'-ac', '2',
					'-f', 'hls',
					'-hls_time', (string) self::SEGMENT_DURATION_SECONDS,
					'-hls_playlist_type', 'vod',
					'-hls_segment_filename', $segmentPattern,
					$playlistFile,
				]);
				$process->setTimeout(self::TRANSCODE_TIMEOUT_SECONDS);
				$process->mustRun();

				$totalSegments = $this->collectRenditionArtifacts(
					$workDir,
					$name,
					$playlistFile,
					$publicToken,
					null,
					null,
					$newVariants,
					$storedNewKeys,
				);
				$renditionNames[] = $name;

				$masterContent = self::rewritePlaylist(
					"#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-STREAM-INF:BANDWIDTH=128000\naudio.m3u8\n",
					$publicToken,
				);
			}

			$masterStored = $this->storeString($masterContent, 'm3u8');
			$storedNewKeys[] = $masterStored['storageKey'];
			$newVariants[self::MASTER_VARIANT_KEY] = [
				'storageKey' => $masterStored['storageKey'],
				'mimeType' => self::PLAYLIST_MIME_TYPE,
				'size' => $masterStored['size'],
				'checksum' => $masterStored['checksum'],
				'width' => null,
				'height' => null,
			];

			$oldStorageKeysToDelete = [];
			foreach ($this->variants->findByAsset($asset) as $existingVariant) {
				if (str_starts_with($existingVariant->getKey(), 'hls_')) {
					$oldStorageKeysToDelete[] = $existingVariant->getStorageKey();
					$this->entityManager->remove($existingVariant);
				}
			}

			foreach ($newVariants as $variantKey => $artifact) {
				$this->entityManager->persist(new DigitalAssetVariant(
					$asset,
					$variantKey,
					$artifact['storageKey'],
					$artifact['mimeType'],
					$artifact['size'],
					$artifact['width'],
					$artifact['height'],
					$artifact['checksum'],
				));
			}

			$metadata['hls'] = [
				'ready' => true,
				'masterVariant' => self::MASTER_VARIANT_KEY,
				'masterUrl' => sprintf('/assets/%s/hls/master.m3u8', $publicToken),
				'renditions' => $renditionNames,
				'segmentCount' => $totalSegments,
			];
			$asset->setMediaMetadata($metadata);

			$this->entityManager->flush();
			$flushed = true;

			foreach ($oldStorageKeysToDelete as $oldKey) {
				try {
					$this->storage->delete($oldKey);
				} catch (\Throwable) {
					// Best-effort removal of superseded HLS artifacts.
				}
			}
		} catch (\Throwable $exception) {
			if (!$flushed) {
				foreach ($storedNewKeys as $newKey) {
					try {
						$this->storage->delete($newKey);
					} catch (\Throwable) {
						// Best-effort cleanup of orphaned HLS artifacts on error.
					}
				}
			}
			throw $exception;
		} finally {
			$this->filesystem->remove($workDir);
			$localSource->cleanup();
		}
	}

	/**
	 * @return list<array{name:string,width:int,height:int,bandwidth:int,maxrate:string,bufsize:string}>
	 */
	private function selectVideoRenditions(int $sourceHeight): array
	{
		$renditions = [
			[
				'name' => '480p',
				'width' => 854,
				'height' => 480,
				'bandwidth' => 1328000,
				'maxrate' => '1200k',
				'bufsize' => '2400k',
			],
		];

		if ($sourceHeight >= 720) {
			$renditions[] = [
				'name' => '720p',
				'width' => 1280,
				'height' => 720,
				'bandwidth' => 2928000,
				'maxrate' => '2800k',
				'bufsize' => '5600k',
			];
		}

		return $renditions;
	}

	/**
	 * @param array<string,array{storageKey:string,mimeType:string,size:int,checksum:string,width:?int,height:?int}> $newVariants
	 * @param list<string>                                                                                           $storedNewKeys
	 */
	private function collectRenditionArtifacts(
		string $workDir,
		string $renditionName,
		string $playlistFile,
		string $publicToken,
		?int $width,
		?int $height,
		array &$newVariants,
		array &$storedNewKeys,
	): int {
		if (!is_file($playlistFile)) {
			throw new \RuntimeException(sprintf('FFmpeg did not generate HLS playlist for rendition "%s".', $renditionName));
		}

		$rawPlaylist = file_get_contents($playlistFile);
		if (false === $rawPlaylist) {
			throw new \RuntimeException(sprintf('Unable to read generated HLS playlist for rendition "%s".', $renditionName));
		}

		$segmentFiles = glob($workDir.'/'.$renditionName.'_seg_*.ts');
		$segmentFiles = false === $segmentFiles ? [] : $segmentFiles;
		sort($segmentFiles);

		foreach ($segmentFiles as $segmentFile) {
			$basename = basename($segmentFile);
			$variantKey = self::variantKeyForSegment($basename);
			if (null === $variantKey) {
				continue;
			}

			$stored = $this->storeFile($segmentFile, 'ts');
			$storedNewKeys[] = $stored['storageKey'];
			$newVariants[$variantKey] = [
				'storageKey' => $stored['storageKey'],
				'mimeType' => self::SEGMENT_MIME_TYPE,
				'size' => $stored['size'],
				'checksum' => $stored['checksum'],
				'width' => $width,
				'height' => $height,
			];
		}

		$rewrittenPlaylist = self::rewritePlaylist($rawPlaylist, $publicToken);
		$storedPlaylist = $this->storeString($rewrittenPlaylist, 'm3u8');
		$storedNewKeys[] = $storedPlaylist['storageKey'];
		$playlistVariantKey = self::variantKeyForSegment($renditionName.'.m3u8');
		if (null === $playlistVariantKey) {
			throw new \RuntimeException(sprintf('Invalid HLS rendition name "%s".', $renditionName));
		}

		$newVariants[$playlistVariantKey] = [
			'storageKey' => $storedPlaylist['storageKey'],
			'mimeType' => self::PLAYLIST_MIME_TYPE,
			'size' => $storedPlaylist['size'],
			'checksum' => $storedPlaylist['checksum'],
			'width' => $width,
			'height' => $height,
		];

		return count($segmentFiles);
	}

	/**
	 * @return array{storageKey:string,size:int,checksum:string}
	 */
	private function storeFile(string $path, string $extension): array
	{
		$size = @filesize($path);
		$checksum = @hash_file('sha256', $path);
		if (false === $size || false === $checksum) {
			throw new \RuntimeException('Unable to inspect generated HLS artifact.');
		}

		$handle = @fopen($path, 'rb');
		if (false === $handle) {
			throw new \RuntimeException('Unable to open generated HLS artifact for storage.');
		}

		$storageKey = $this->generateStorageKey($extension);
		try {
			$this->storage->writeStream($storageKey, $handle, null);
		} finally {
			fclose($handle);
		}

		return [
			'storageKey' => $storageKey,
			'size' => $size,
			'checksum' => $checksum,
		];
	}

	/**
	 * @return array{storageKey:string,size:int,checksum:string}
	 */
	private function storeString(string $content, string $extension): array
	{
		$stream = fopen('php://temp', 'w+b');
		if (false === $stream) {
			throw new \RuntimeException('Unable to allocate temporary stream for HLS playlist.');
		}

		$storageKey = $this->generateStorageKey($extension);
		try {
			fwrite($stream, $content);
			rewind($stream);
			$this->storage->writeStream($storageKey, $stream, null);
		} finally {
			fclose($stream);
		}

		return [
			'storageKey' => $storageKey,
			'size' => strlen($content),
			'checksum' => hash('sha256', $content),
		];
	}

	private function generateStorageKey(string $extension): string
	{
		$token = bin2hex(random_bytes(32));

		return substr($token, 0, 2).'/'.$token.'.'.$extension;
	}

	private function assertSafeBinary(string $binary): string
	{
		$trimmed = trim($binary);
		if ('' === $trimmed || str_contains($trimmed, "\0")) {
			throw new \InvalidArgumentException('FFmpeg binary path must be non-empty.');
		}

		return $trimmed;
	}
}
