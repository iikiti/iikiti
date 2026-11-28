<?php

declare(strict_types=1);

namespace iikiti\Extension\MediaAv\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Service\DigitalAssetProcessorInterface;
use iikiti\CMS\Storage\AssetStorageInterface;
use iikiti\Extension\MediaAv\Message\TranscodeMediaAsset;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;

/**
 * Processes uploaded video and audio digital assets using FFmpeg/FFprobe:
 * extracts duration, dimensions, codec/stream metadata, video poster frames
 * (JPEG + optional WebP), audio waveform peaks, and dispatches optional HLS transcoding.
 */
final class FfmpegMediaProcessor implements DigitalAssetProcessorInterface
{
	public const KEY = 'ffmpeg_media';

	private const PROBE_TIMEOUT_SECONDS = 30;
	private const POSTER_TIMEOUT_SECONDS = 60;
	private const MAX_POSTER_DIMENSION = 1920;

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
		private readonly FfprobeMetadataParser $metadataParser,
		private readonly WaveformPeakExtractor $waveformExtractor,
		private readonly ?MessageBusInterface $messageBus = null,
		#[Autowire('%media_av_ffmpeg_binary%')]
		private readonly string $ffmpegBinary = 'ffmpeg',
		#[Autowire('%media_av_ffprobe_binary%')]
		private readonly string $ffprobeBinary = 'ffprobe',
		#[Autowire('%media_av_hls_enabled%')]
		private readonly bool $hlsEnabled = false,
		private readonly Filesystem $filesystem = new Filesystem(),
	) {
	}

	public function getKey(): string
	{
		return self::KEY;
	}

	public function supportsMimePattern(string $mimePattern): bool
	{
		$normalized = strtolower(trim($mimePattern));

		return 'video/*' === $normalized
			|| 'audio/*' === $normalized
			|| isset(self::EXTENSION_MAP[$normalized]);
	}

	public function process(AbstractDigitalAsset $asset, AssetWorkflowDefinition $workflow): void
	{
		$sourceKey = $asset->getStorageKey();
		if (null === $sourceKey) {
			throw new \RuntimeException('Uploaded media asset has no stored file.');
		}

		$mimeType = strtolower(trim($asset->getMimeType()));
		$extension = self::EXTENSION_MAP[$mimeType] ?? throw new \InvalidArgumentException(sprintf('Unsupported audio/video MIME type "%s".', $mimeType));

		$localSource = $this->storage->materializeToLocalTemp($sourceKey, $extension);
		try {
			$sourcePath = $localSource->path;
			if ('' === $sourcePath || str_contains($sourcePath, "\0") || !is_file($sourcePath)) {
				throw new \RuntimeException('Materialized media asset file could not be read.');
			}

			$probed = $this->probe($sourcePath);
			$asset->setDurationMs($probed['durationMs']);
			if ($probed['hasVideo']) {
				$asset->setWidth($probed['width']);
				$asset->setHeight($probed['height']);
			}

			$existingMetadata = $asset->getMediaMetadata();
			$mediaMetadata = is_array($existingMetadata) ? $existingMetadata : [];
			$mediaMetadata = array_merge($mediaMetadata, $probed);

			$options = $workflow->getOptions();

			if ($probed['hasVideo'] && false !== ($options['poster'] ?? true)) {
				$offsetSeconds = $this->computePosterOffsetSeconds($probed['durationMs'], $options['poster_offset_percent'] ?? 10);
				$this->extractPosterVariants($asset, $sourcePath, $offsetSeconds);
			}

			if ($probed['hasAudio'] && false !== ($options['waveform'] ?? true)) {
				$buckets = $this->resolveWaveformBuckets($options['waveform_buckets'] ?? WaveformPeakExtractor::DEFAULT_BUCKETS);
				$mediaMetadata['waveform'] = $this->waveformExtractor->extractFromAudioFile($sourcePath, $this->ffmpegBinary, $buckets);
			}

			$asset->setMediaMetadata($mediaMetadata);
			$this->entityManager->flush();

			$shouldTranscodeHls = true === ($options['hls'] ?? false) || ($this->hlsEnabled && $probed['hasVideo']);
			$assetId = $asset->getId();
			if ($shouldTranscodeHls && null !== $this->messageBus && null !== $assetId && ($probed['hasVideo'] || $probed['hasAudio'])) {
				$this->messageBus->dispatch(new TranscodeMediaAsset($assetId));
			}
		} finally {
			$localSource->cleanup();
		}
	}

	/**
	 * Calculates the poster frame timestamp in seconds from duration and percentage offset.
	 */
	public function computePosterOffsetSeconds(?int $durationMs, mixed $rawPercent): float
	{
		if (null === $durationMs || $durationMs <= 1000) {
			return 0.0;
		}

		$percent = is_int($rawPercent) || is_float($rawPercent) || (is_string($rawPercent) && is_numeric($rawPercent))
			? (float) $rawPercent
			: 10.0;
		if (!is_finite($percent)) {
			$percent = 10.0;
		}
		$percent = max(0.0, min(100.0, $percent));

		$durationSeconds = $durationMs / 1000.0;
		$rawOffset = $durationSeconds * ($percent / 100.0);

		return round(max(0.5, min($durationSeconds - 0.5, $rawOffset)), 3);
	}

	/**
	 * @return array{
	 *     durationMs: ?int,
	 *     width: ?int,
	 *     height: ?int,
	 *     bitrate: ?int,
	 *     videoCodec: ?string,
	 *     audioCodec: ?string,
	 *     frameRate: ?float,
	 *     sampleRate: ?int,
	 *     channels: ?int,
	 *     hasVideo: bool,
	 *     hasAudio: bool,
	 * }
	 */
	private function probe(string $sourcePath): array
	{
		$binary = $this->assertSafeBinary($this->ffprobeBinary, 'FFprobe');
		$process = new Process([
			$binary,
			'-v', 'quiet',
			'-print_format', 'json',
			'-show_format',
			'-show_streams',
			$sourcePath,
		]);
		$process->setInput(null);
		$process->setTimeout(self::PROBE_TIMEOUT_SECONDS);
		$process->mustRun();

		return $this->metadataParser->parse($process->getOutput());
	}

	private function extractPosterVariants(AbstractDigitalAsset $asset, string $sourcePath, float $offsetSeconds): void
	{
		$ffmpeg = $this->assertSafeBinary($this->ffmpegBinary, 'FFmpeg');
		$jpegTemp = $this->storage->createTemporaryPath('image/jpeg');
		$webpTemp = null;

		try {
			$this->runPosterExtraction($ffmpeg, $sourcePath, $offsetSeconds, $jpegTemp);
			if ((!is_file($jpegTemp) || 0 === (@filesize($jpegTemp) ?: 0)) && $offsetSeconds > 0.0) {
				$this->runPosterExtraction($ffmpeg, $sourcePath, 0.0, $jpegTemp);
			}

			$jpegDimensions = @getimagesize($jpegTemp);
			if (false === $jpegDimensions) {
				throw new \RuntimeException('FFmpeg poster frame extraction produced an invalid JPEG image.');
			}

			// Attempt optional WebP conversion from the extracted JPEG poster before
			// storeTemporaryFile() moves $jpegTemp into permanent storage.
			$webpTemp = $this->storage->createTemporaryPath('image/webp');
			$webpReady = false;
			try {
				$webpProcess = new Process([
					$ffmpeg,
					'-nostdin',
					'-y',
					'-v', 'error',
					'-threads', '2',
					'-i', $jpegTemp,
					'-frames:v', '1',
					'-c:v', 'libwebp',
					'-quality', '82',
					$webpTemp,
				]);
				$webpProcess->setTimeout(self::POSTER_TIMEOUT_SECONDS);
				$webpProcess->mustRun();
				$webpDimensions = @getimagesize($webpTemp);
				if (false !== $webpDimensions) {
					$webpReady = true;
				}
			} catch (\Throwable) {
				// libwebp encoder may not be available in all FFmpeg builds; JPEG poster still succeeds.
				$webpReady = false;
			}

			$this->persistImageVariant($asset, 'poster', $jpegTemp, 'image/jpeg', $jpegDimensions[0], $jpegDimensions[1]);

			if ($webpReady) {
				$webpDims = @getimagesize($webpTemp);
				if (false !== $webpDims) {
					$this->persistImageVariant($asset, 'poster_webp', $webpTemp, 'image/webp', $webpDims[0], $webpDims[1]);
				}
			}
		} finally {
			$this->filesystem->remove($jpegTemp);
			if (null !== $webpTemp) {
				$this->filesystem->remove($webpTemp);
			}
		}
	}

	private function runPosterExtraction(string $ffmpeg, string $sourcePath, float $offsetSeconds, string $outputPath): void
	{
		$process = new Process([
			$ffmpeg,
			'-nostdin',
			'-y',
			'-v', 'error',
			'-threads', '2',
			'-ss', sprintf('%.3F', $offsetSeconds),
			'-i', $sourcePath,
			'-frames:v', '1',
			'-vf', sprintf('scale=min(%d\\,iw):-2', self::MAX_POSTER_DIMENSION),
			'-q:v', '3',
			$outputPath,
		]);
		$process->setTimeout(self::POSTER_TIMEOUT_SECONDS);
		$process->mustRun();
	}

	private function persistImageVariant(
		AbstractDigitalAsset $asset,
		string $variantKey,
		string $temporaryPath,
		string $mimeType,
		int $width,
		int $height,
	): void {
		$variantSize = filesize($temporaryPath) ?: 0;
		$variantChecksum = hash_file('sha256', $temporaryPath);
		$storageKey = $this->storage->storeTemporaryFile($temporaryPath, $mimeType);

		$existing = $this->variants->findByAssetAndKey($asset, $variantKey);
		$previousKey = null;
		if (null !== $existing) {
			$previousKey = $existing->getStorageKey();
			$existing->
				setStorageKey($storageKey)->
				setMimeType($mimeType)->
				setSize($variantSize)->
				setWidth($width)->
				setHeight($height)->
				setChecksum(is_string($variantChecksum) ? $variantChecksum : null)->
				setFormat(null)->
				setQuality(null)->
				setFocalCrop(false);
		} else {
			$this->entityManager->persist(new DigitalAssetVariant(
				$asset,
				$variantKey,
				$storageKey,
				$mimeType,
				$variantSize,
				$width,
				$height,
				is_string($variantChecksum) ? $variantChecksum : null,
			));
		}

		try {
			$this->entityManager->flush();
		} catch (\Throwable $exception) {
			$this->storage->delete($storageKey);
			throw $exception;
		}

		if (null !== $previousKey && $previousKey !== $storageKey) {
			$this->storage->delete($previousKey);
		}
	}

	private function resolveWaveformBuckets(mixed $rawBuckets): int
	{
		$filtered = filter_var($rawBuckets, FILTER_VALIDATE_INT);
		if (false === $filtered) {
			return WaveformPeakExtractor::DEFAULT_BUCKETS;
		}

		return max(16, min(512, $filtered));
	}

	private function assertSafeBinary(string $binary, string $label): string
	{
		$trimmed = trim($binary);
		if ('' === $trimmed || str_contains($trimmed, "\0")) {
			throw new \InvalidArgumentException(sprintf('%s binary path must be non-empty.', $label));
		}

		return $trimmed;
	}
}
