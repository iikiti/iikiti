<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Storage\AssetStorageInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ImageThumbnailProcessor implements DigitalAssetProcessorInterface
{
	/** @var list<int> */
	public const DEFAULT_RESPONSIVE_WIDTHS = [320, 640, 960, 1280, 1920];

	/** @var list<string> */
	public const DEFAULT_FORMATS = ['avif', 'webp', 'original'];

	/** @var list<array{name: string, width: int, height: int}> */
	public const DEFAULT_NAMED_VARIANTS = [
		['name' => 'thumbnail', 'width' => 320, 'height' => 320],
		['name' => 'preview', 'width' => 1280, 'height' => 1280],
	];

	/** @var array<string, ?int> */
	private const DEFAULT_QUALITY_BY_FORMAT = [
		'avif' => 65,
		'webp' => 80,
		'jpg' => 82,
		'png' => null,
		'gif' => null,
	];

	/** @var array<string, string> */
	private const FORMAT_TO_MIME = [
		'jpg' => 'image/jpeg',
		'png' => 'image/png',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
	];

	/** @var list<string>|null */
	private ?array $supportedFormats = null;

	/**
	 * @param (\Closure(): list<string>)|null      $formatDetector
	 * @param (\Closure(list<string>): void)|null  $commandRunner
	 */
	public function __construct(
		private readonly AssetStorageInterface $storage,
		private readonly DigitalAssetVariantRepository $variants,
		private readonly EntityManagerInterface $entityManager,
		private readonly Filesystem $filesystem = new Filesystem(),
		private readonly ?\Closure $formatDetector = null,
		private readonly ?\Closure $commandRunner = null,
	) {
	}

	public function getKey(): string
	{
		return 'image_thumbnail';
	}

	public function supportsMimePattern(string $mimePattern): bool
	{
		return 'image/*' === $mimePattern;
	}

	/**
	 * Computes the largest source crop rectangle matching the target aspect ratio,
	 * centered as closely as possible around the normalized focal point `(0..1, 0..1)`
	 * and clamped within `[0..srcW, 0..srcH]`.
	 *
	 * @return array{width: int, height: int, x: int, y: int}
	 */
	public static function computeFocalCropGeometry(
		int $srcW,
		int $srcH,
		int $targetW,
		int $targetH,
		float $focalX,
		float $focalY,
	): array {
		if ($srcW < 1 || $srcH < 1 || $targetW < 1 || $targetH < 1) {
			throw new \InvalidArgumentException('Source and target dimensions must be positive integers.');
		}

		$clampedX = max(0.0, min(1.0, $focalX));
		$clampedY = max(0.0, min(1.0, $focalY));

		$srcRatio = $srcW / $srcH;
		$targetRatio = $targetW / $targetH;

		if ($srcRatio > $targetRatio) {
			$cropH = $srcH;
			$cropW = max(1, min($srcW, (int) round($srcH * $targetRatio)));
		} elseif ($srcRatio < $targetRatio) {
			$cropW = $srcW;
			$cropH = max(1, min($srcH, (int) round($srcW / $targetRatio)));
		} else {
			$cropW = $srcW;
			$cropH = $srcH;
		}

		$x = (int) round(($clampedX * $srcW) - ($cropW / 2.0));
		$y = (int) round(($clampedY * $srcH) - ($cropH / 2.0));

		$x = max(0, min($srcW - $cropW, $x));
		$y = max(0, min($srcH - $cropH, $y));

		return [
			'width' => $cropW,
			'height' => $cropH,
			'x' => $x,
			'y' => $y,
		];
	}

	/**
	 * Parses `magick -list format` output and returns lowercased writable format names.
	 *
	 * @return list<string>
	 */
	public static function parseFormatListOutput(string $output): array
	{
		$formats = [];
		foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
			if (1 === preg_match('/^\s*([A-Z0-9]+)\*?\s+[r-]w[-+]\s/i', $line, $matches)) {
				$format = strtolower($matches[1]);
				$formats[$format] = true;
				if ('jpeg' === $format) {
					$formats['jpg'] = true;
				}
			}
		}

		return array_keys($formats);
	}

	/**
	 * Returns cached writable ImageMagick formats supported by the local runtime.
	 *
	 * @return list<string>
	 */
	public function getSupportedFormats(): array
	{
		if (null !== $this->supportedFormats) {
			return $this->supportedFormats;
		}

		if (null !== $this->formatDetector) {
			$detected = ($this->formatDetector)();
			$normalized = [];
			foreach ($detected as $format) {
				$clean = strtolower(trim($format));
				if ('' === $clean) {
					continue;
				}
				$normalized[$clean] = true;
				if ('jpeg' === $clean) {
					$normalized['jpg'] = true;
				}
			}
			$this->supportedFormats = array_keys($normalized);

			return $this->supportedFormats;
		}

		try {
			$process = new Process(['magick', '-list', 'format']);
			$process->setTimeout(10)->run();
			if ($process->isSuccessful()) {
				$this->supportedFormats = self::parseFormatListOutput($process->getOutput());

				return $this->supportedFormats;
			}
		} catch (\Throwable) {
			// Fall through to empty list when ImageMagick cannot be probed.
		}

		$this->supportedFormats = [];

		return $this->supportedFormats;
	}

	public function process(AbstractDigitalAsset $asset, AssetWorkflowDefinition $workflow): void
	{
		$sourceKey = $asset->getStorageKey();
		if (null === $sourceKey) {
			throw new \RuntimeException('Uploaded image has no stored file.');
		}

		$sourceMimeType = $asset->getMimeType();
		$sourceFormat = $this->formatForMimeType($sourceMimeType);

		$localSource = $this->storage->materializeToLocalTemp($sourceKey, $sourceFormat);
		try {
			$source = $localSource->path;
			$dimensions = @getimagesize($source);
			if (false === $dimensions || $dimensions[0] > 20000 || $dimensions[1] > 20000 || $dimensions[0] * $dimensions[1] > 100000000) {
				throw new \RuntimeException('Image dimensions are invalid or exceed the processing limit.');
			}

			[$srcW, $srcH] = $this->effectiveDimensions($source, (int) $dimensions[0], (int) $dimensions[1]);
			$conditions = $workflow->getConditions();
			if (($srcW < (int) ($conditions['min_width'] ?? 0)) || ($srcH < (int) ($conditions['min_height'] ?? 0))) {
				return;
			}

			$options = $workflow->getOptions();
			$jobs = $this->planVariants(
				$srcW,
				$srcH,
				$sourceFormat,
				$options,
				$asset->getEffectiveFocalX(),
				$asset->getEffectiveFocalY(),
			);

			if ([] === $jobs) {
				throw new \InvalidArgumentException('At least one image variant is required.');
			}

			$generatedVariantKeys = [];
			foreach ($jobs as $job) {
				$temporaryPath = $this->storage->createTemporaryPath($job['mimeType']);
				try {
					$command = $this->buildCommand($source, $temporaryPath, $job);
					$this->runCommand($command);

					$outputDimensions = @getimagesize($temporaryPath);
					if (false !== $outputDimensions) {
						$outW = (int) $outputDimensions[0];
						$outH = (int) $outputDimensions[1];
					} elseif ('avif' === $job['format'] && is_file($temporaryPath) && (filesize($temporaryPath) ?: 0) > 0) {
						[$outW, $outH] = $this->estimateOutputDimensions($srcW, $srcH, $job);
					} else {
						throw new \RuntimeException('Thumbnail generation produced an invalid image.');
					}

					$variantSize = filesize($temporaryPath) ?: 0;
					$variantChecksum = hash_file('sha256', $temporaryPath);
					$storageKey = $this->storage->storeTemporaryFile($temporaryPath, $job['mimeType']);
					$existing = $this->variants->findByAssetAndKey($asset, $job['name']);
					$previousStorageKey = null;
					if (null !== $existing) {
						$previousStorageKey = $existing->getStorageKey();
						$existing->
							setStorageKey($storageKey)->
							setMimeType($job['mimeType'])->
							setSize($variantSize)->
							setWidth($outW)->
							setHeight($outH)->
							setChecksum(is_string($variantChecksum) ? $variantChecksum : null)->
							setFormat($job['format'])->
							setQuality($job['quality'])->
							setFocalCrop($job['focalCrop']);
					} else {
						$this->entityManager->persist(new DigitalAssetVariant(
							$asset,
							$job['name'],
							$storageKey,
							$job['mimeType'],
							$variantSize,
							$outW,
							$outH,
							is_string($variantChecksum) ? $variantChecksum : null,
							$job['format'],
							$job['quality'],
							$job['focalCrop'],
						));
					}
					try {
						$this->entityManager->flush();
					} catch (\Throwable $exception) {
						try {
							$this->storage->delete($storageKey);
						} catch (\Throwable) {
							// Best-effort cleanup of newly stored variant on flush failure.
						}
						throw $exception;
					}
					$generatedVariantKeys[$job['name']] = true;
					if (null !== $previousStorageKey && $previousStorageKey !== $storageKey) {
						try {
							$this->storage->delete($previousStorageKey);
						} catch (\Throwable) {
							// Best-effort cleanup of superseded variant storage key.
						}
					}
				} finally {
					$this->filesystem->remove($temporaryPath);
				}
			}

			$staleStorageKeys = [];
			$removedObsolete = false;
			foreach ($this->variants->findByAsset($asset) as $existingVariant) {
				$existingKey = $existingVariant->getKey();
				if (
					isset($generatedVariantKeys[$existingKey])
					|| str_starts_with($existingKey, 'hls_')
					|| 'poster' === $existingKey
					|| str_starts_with($existingKey, 'poster_')
				) {
					continue;
				}
				$staleStorageKeys[] = $existingVariant->getStorageKey();
				$this->entityManager->remove($existingVariant);
				$removedObsolete = true;
			}
			if ($removedObsolete) {
				$this->entityManager->flush();
				foreach (array_values(array_unique($staleStorageKeys)) as $staleKey) {
					try {
						$this->storage->delete($staleKey);
					} catch (\Throwable) {
						// Best-effort cleanup of obsolete image variant storage key.
					}
				}
			}
		} finally {
			$localSource->cleanup();
		}
	}

	/**
	 * Plans all named and responsive variants for a source image, honoring no-upscale
	 * bounds, focal-point crop geometry, and available ImageMagick format encoders.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return list<array{
	 *     name: string,
	 *     width: int,
	 *     height: ?int,
	 *     format: string,
	 *     mimeType: string,
	 *     quality: ?int,
	 *     focalCrop: bool,
	 *     cropGeometry: ?array{width: int, height: int, x: int, y: int},
	 * }>
	 */
	public function planVariants(
		int $srcW,
		int $srcH,
		string $sourceFormat,
		array $options,
		float $focalX = 0.5,
		float $focalY = 0.5,
	): array {
		$jobs = [];
		$seenNames = [];

		$qualityOverrides = is_array($options['quality'] ?? null) ? $options['quality'] : [];

		$namedVariants = array_key_exists('variants', $options) ? $options['variants'] : self::DEFAULT_NAMED_VARIANTS;
		if (!is_array($namedVariants)) {
			throw new \InvalidArgumentException('Image variants option must be a list.');
		}

		foreach ($namedVariants as $variant) {
			if (!is_array($variant)) {
				throw new \InvalidArgumentException('Image variant configuration must be an object.');
			}
			$name = (string) ($variant['name'] ?? '');
			$width = filter_var($variant['width'] ?? null, FILTER_VALIDATE_INT);
			$aspectRatio = $this->parseAspectRatio($variant['aspect_ratio'] ?? null);
			$height = array_key_exists('height', $variant) && null !== $variant['height']
				? filter_var($variant['height'], FILTER_VALIDATE_INT)
				: (null !== $aspectRatio && false !== $width ? max(1, (int) round($width / $aspectRatio)) : false);

			if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $name) || false === $width || false === $height || $width < 1 || $height < 1 || $width > 4096 || $height > 4096) {
				throw new \InvalidArgumentException('Image variant name or dimensions are invalid.');
			}

			$requestedFormat = is_string($variant['format'] ?? null) ? strtolower(trim($variant['format'])) : 'original';
			$resolvedFormat = $this->resolveOutputFormat($requestedFormat, $sourceFormat);
			if (null === $resolvedFormat) {
				continue;
			}

			$focalCrop = true === ($variant['crop'] ?? false) || null !== $aspectRatio;
			$cropGeometry = $focalCrop
				? self::computeFocalCropGeometry($srcW, $srcH, $width, $height, $focalX, $focalY)
				: null;

			$quality = $this->resolveQuality($resolvedFormat, $variant['quality'] ?? $qualityOverrides[$resolvedFormat] ?? null);

			$seenNames[$name] = true;
			$jobs[] = [
				'name' => $name,
				'width' => $width,
				'height' => $height,
				'format' => $resolvedFormat,
				'mimeType' => self::FORMAT_TO_MIME[$resolvedFormat],
				'quality' => $quality,
				'focalCrop' => $focalCrop,
				'cropGeometry' => $cropGeometry,
			];
		}

		$responsiveWidths = array_key_exists('responsive_widths', $options)
			? $options['responsive_widths']
			: self::DEFAULT_RESPONSIVE_WIDTHS;

		if (is_array($responsiveWidths) && [] !== $responsiveWidths) {
			$rawFormats = array_key_exists('formats', $options) && is_array($options['formats'])
				? $options['formats']
				: self::DEFAULT_FORMATS;

			$resolvedFormats = [];
			foreach ($rawFormats as $rawFormat) {
				if (!is_string($rawFormat)) {
					continue;
				}
				$resolved = $this->resolveOutputFormat(strtolower(trim($rawFormat)), $sourceFormat);
				if (null !== $resolved) {
					$resolvedFormats[$resolved] = true;
				}
			}
			$formats = array_keys($resolvedFormats);

			$validatedWidths = [];
			foreach ($responsiveWidths as $rawWidth) {
				$w = filter_var($rawWidth, FILTER_VALIDATE_INT);
				if (false === $w || $w < 1 || $w > 4096) {
					throw new \InvalidArgumentException('Responsive width values must be integers between 1 and 4096.');
				}
				// Never upscale above source width.
				if ($w <= $srcW) {
					$validatedWidths[$w] = true;
				}
			}
			$widths = array_keys($validatedWidths);
			sort($widths);

			foreach ($widths as $w) {
				foreach ($formats as $format) {
					$name = sprintf('w%d_%s', $w, $format);
					if (isset($seenNames[$name])) {
						continue;
					}
					$seenNames[$name] = true;
					$jobs[] = [
						'name' => $name,
						'width' => $w,
						'height' => null,
						'format' => $format,
						'mimeType' => self::FORMAT_TO_MIME[$format],
						'quality' => $this->resolveQuality($format, $qualityOverrides[$format] ?? null),
						'focalCrop' => false,
						'cropGeometry' => null,
					];
				}
			}
		}

		return $jobs;
	}

	/**
	 * Builds the ImageMagick command line preserving ICC color profiles while stripping
	 * EXIF/GPS/IPTC/XMP/comment metadata.
	 *
	 * @param array{
	 *     name: string,
	 *     width: int,
	 *     height: ?int,
	 *     format: string,
	 *     mimeType: string,
	 *     quality: ?int,
	 *     focalCrop: bool,
	 *     cropGeometry: ?array{width: int, height: int, x: int, y: int},
	 * } $job
	 *
	 * @return list<string>
	 */
	public function buildCommand(string $sourcePath, string $outputPath, array $job): array
	{
		$args = [
			'magick',
			'-limit', 'memory', '256MiB',
			'-limit', 'map', '512MiB',
			'-limit', 'disk', '1GiB',
			$sourcePath.'[0]',
			'-auto-orient',
		];

		if (null !== $job['cropGeometry']) {
			$crop = $job['cropGeometry'];
			$args[] = '-crop';
			$args[] = sprintf('%dx%d+%d+%d', $crop['width'], $crop['height'], $crop['x'], $crop['y']);
			$args[] = '+repage';
		}

		$resizeSpec = null !== $job['height']
			? sprintf('%dx%d>', $job['width'], $job['height'])
			: sprintf('%dx>', $job['width']);
		$args[] = '-resize';
		$args[] = $resizeSpec;

		// Preserve ICC color profiles while stripping EXIF, GPS, IPTC, XMP, and comments.
		$args[] = '+profile';
		$args[] = '!icc,*';
		$args[] = '+set';
		$args[] = 'comment';

		if (null !== $job['quality']) {
			$args[] = '-quality';
			$args[] = (string) $job['quality'];
		}

		$args[] = $outputPath;

		return $args;
	}

	/**
	 * @param list<string> $command
	 */
	private function runCommand(array $command): void
	{
		if (null !== $this->commandRunner) {
			($this->commandRunner)($command);

			return;
		}

		$process = new Process($command);
		$process->setTimeout(30)->mustRun();
	}

	private function formatForMimeType(string $mimeType): string
	{
		return match (strtolower(trim($mimeType))) {
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			default => throw new \InvalidArgumentException('Unsupported image MIME type.'),
		};
	}

	private function resolveOutputFormat(string $requestedFormat, string $sourceFormat): ?string
	{
		if ('original' === $requestedFormat || '' === $requestedFormat) {
			return $sourceFormat;
		}

		$normalized = 'jpeg' === $requestedFormat ? 'jpg' : $requestedFormat;
		if (!isset(self::FORMAT_TO_MIME[$normalized])) {
			throw new \InvalidArgumentException(sprintf('Unsupported target image format "%s".', $requestedFormat));
		}

		// Modern formats degrade gracefully when ImageMagick lacks the required delegate.
		if (in_array($normalized, ['avif', 'webp'], true) && $normalized !== $sourceFormat) {
			$supported = $this->getSupportedFormats();
			if (!in_array($normalized, $supported, true)) {
				return null;
			}
		}

		return $normalized;
	}

	private function resolveQuality(string $format, mixed $override): ?int
	{
		if (null !== $override) {
			$parsed = filter_var($override, FILTER_VALIDATE_INT);
			if (false === $parsed || $parsed < 1 || $parsed > 100) {
				throw new \InvalidArgumentException('Image quality must be an integer between 1 and 100.');
			}

			return $parsed;
		}

		return self::DEFAULT_QUALITY_BY_FORMAT[$format] ?? null;
	}

	private function parseAspectRatio(mixed $value): ?float
	{
		if (null === $value || '' === $value) {
			return null;
		}
		if (is_int($value) || is_float($value)) {
			if (!is_finite((float) $value) || (float) $value <= 0.0) {
				throw new \InvalidArgumentException('Aspect ratio must be positive.');
			}

			return (float) $value;
		}
		if (is_string($value)) {
			$trimmed = trim($value);
			if (1 === preg_match('/^(\d+(?:\.\d+)?)\s*[:\/]\s*(\d+(?:\.\d+)?)$/', $trimmed, $matches)) {
				$numerator = (float) $matches[1];
				$denominator = (float) $matches[2];
				if ($numerator <= 0.0 || $denominator <= 0.0) {
					throw new \InvalidArgumentException('Aspect ratio components must be positive.');
				}

				return $numerator / $denominator;
			}
			if (is_numeric($trimmed) && (float) $trimmed > 0.0) {
				return (float) $trimmed;
			}
		}

		throw new \InvalidArgumentException('Invalid aspect_ratio value.');
	}

	/**
	 * Accounts for EXIF orientation 5..8 so crop math and no-upscale checks match
	 * the post-`-auto-orient` pixel dimensions.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function effectiveDimensions(string $sourcePath, int $rawW, int $rawH): array
	{
		if (function_exists('exif_read_data')) {
			$exif = @exif_read_data($sourcePath, 'IFD0', true);
			if (is_array($exif)) {
				$orientation = $exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? null;
				if ((is_int($orientation) || (is_string($orientation) && ctype_digit($orientation))) && in_array((int) $orientation, [5, 6, 7, 8], true)) {
					return [$rawH, $rawW];
				}
			}
		}

		return [$rawW, $rawH];
	}

	/**
	 * Fallback dimension estimator when PHP `getimagesize()` cannot inspect an AVIF file.
	 *
	 * @param array{
	 *     width: int,
	 *     height: ?int,
	 *     cropGeometry: ?array{width: int, height: int, x: int, y: int},
	 * } $job
	 *
	 * @return array{0: int, 1: int}
	 */
	private function estimateOutputDimensions(int $srcW, int $srcH, array $job): array
	{
		$baseW = $job['cropGeometry']['width'] ?? $srcW;
		$baseH = $job['cropGeometry']['height'] ?? $srcH;

		if (null === $job['height']) {
			if ($baseW <= $job['width']) {
				return [$baseW, $baseH];
			}
			$scale = $job['width'] / $baseW;

			return [$job['width'], max(1, (int) round($baseH * $scale))];
		}

		$scale = min(1.0, $job['width'] / $baseW, $job['height'] / $baseH);

		return [
			max(1, (int) round($baseW * $scale)),
			max(1, (int) round($baseH * $scale)),
		];
	}
}
