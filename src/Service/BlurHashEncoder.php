<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

/**
 * Pure-PHP BlurHash encoder and dominant color extractor with zero external dependencies.
 *
 * Implements the Wolt BlurHash base83 DCT specification and saturation-weighted
 * bucketed RGB dominant color extraction, using GD when available for image decoding.
 */
final class BlurHashEncoder
{
	private const BASE83_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';
	private const MAX_SAMPLE_DIMENSION = 32;

	/**
	 * Encodes an RGB pixel matrix into a BlurHash string.
	 *
	 * Accepts either a 2D pixel grid `$pixels[$y][$x] = [$r, $g, $b]` or a flat
	 * row-major list of `[$r, $g, $b]` tuples of length `$width * $height`.
	 *
	 * @param array<int,mixed> $pixels
	 */
	public function encode(
		array $pixels,
		int $width,
		int $height,
		int $componentsX = 4,
		int $componentsY = 3,
	): string {
		if ($width < 1 || $height < 1) {
			throw new \InvalidArgumentException('BlurHash image width and height must be positive integers.');
		}
		if ($componentsX < 1 || $componentsX > 9 || $componentsY < 1 || $componentsY > 9) {
			throw new \InvalidArgumentException('BlurHash component counts must be between 1 and 9.');
		}

		$grid = $this->normalizePixels($pixels, $width, $height);

		$linearR = [];
		$linearG = [];
		$linearB = [];
		for ($y = 0; $y < $height; ++$y) {
			$rowR = [];
			$rowG = [];
			$rowB = [];
			for ($x = 0; $x < $width; ++$x) {
				[$r, $g, $b] = $grid[$y][$x];
				$rowR[$x] = self::srgbToLinear($r);
				$rowG[$x] = self::srgbToLinear($g);
				$rowB[$x] = self::srgbToLinear($b);
			}
			$linearR[$y] = $rowR;
			$linearG[$y] = $rowG;
			$linearB[$y] = $rowB;
		}

		$cosX = [];
		for ($i = 0; $i < $componentsX; ++$i) {
			$cosX[$i] = [];
			for ($x = 0; $x < $width; ++$x) {
				$cosX[$i][$x] = cos(M_PI * $i * $x / $width);
			}
		}

		$cosY = [];
		for ($j = 0; $j < $componentsY; ++$j) {
			$cosY[$j] = [];
			for ($y = 0; $y < $height; ++$y) {
				$cosY[$j][$y] = cos(M_PI * $j * $y / $height);
			}
		}

		$factors = [];
		$pixelCount = (float) ($width * $height);
		for ($j = 0; $j < $componentsY; ++$j) {
			for ($i = 0; $i < $componentsX; ++$i) {
				$normalisation = (0 === $i && 0 === $j) ? 1.0 : 2.0;
				$r = 0.0;
				$g = 0.0;
				$b = 0.0;

				for ($y = 0; $y < $height; ++$y) {
					$cy = $cosY[$j][$y];
					$rowR = $linearR[$y];
					$rowG = $linearG[$y];
					$rowB = $linearB[$y];
					$cxRow = $cosX[$i];

					for ($x = 0; $x < $width; ++$x) {
						$basis = $cxRow[$x] * $cy;
						$r += $basis * $rowR[$x];
						$g += $basis * $rowG[$x];
						$b += $basis * $rowB[$x];
					}
				}

				$scale = $normalisation / $pixelCount;
				$factors[] = [$r * $scale, $g * $scale, $b * $scale];
			}
		}

		$dc = $factors[0];
		$ac = array_slice($factors, 1);

		$sizeFlag = ($componentsX - 1) + ($componentsY - 1) * 9;
		$hash = self::encode83($sizeFlag, 1);

		if ([] !== $ac) {
			$actualMaximum = 0.0;
			foreach ($ac as $component) {
				$actualMaximum = max($actualMaximum, abs($component[0]), abs($component[1]), abs($component[2]));
			}
			$quantisedMaximum = (int) max(0, min(82, (int) floor($actualMaximum * 166.0 - 0.5)));
			$maximumValue = ($quantisedMaximum + 1) / 166.0;
			$hash .= self::encode83($quantisedMaximum, 1);
		} else {
			$maximumValue = 1.0;
			$hash .= self::encode83(0, 1);
		}

		$hash .= self::encode83(self::encodeDc($dc[0], $dc[1], $dc[2]), 4);

		foreach ($ac as $component) {
			$hash .= self::encode83(self::encodeAc($component[0], $component[1], $component[2], $maximumValue), 2);
		}

		return $hash;
	}

	/**
	 * Extracts a representative dominant hex color (`#rrggbb`) from an RGB pixel grid
	 * using saturation-weighted color bucketing.
	 *
	 * @param array<int,mixed> $pixels
	 */
	public function extractDominantColor(array $pixels, int $width, int $height): string
	{
		if ($width < 1 || $height < 1) {
			throw new \InvalidArgumentException('Image width and height must be positive integers.');
		}

		$grid = $this->normalizePixels($pixels, $width, $height);
		$flat = [];
		for ($y = 0; $y < $height; ++$y) {
			for ($x = 0; $x < $width; ++$x) {
				$flat[] = $grid[$y][$x];
			}
		}

		return $this->extractDominantColorFromSamples($flat);
	}

	/**
	 * Decodes an image file via GD (when supported) and returns both its BlurHash
	 * and dominant color, or `null` when GD cannot decode the file format.
	 *
	 * @return array{blurhash: string, dominantColor: string}|null
	 */
	public function encodeFromImageFile(string $path, int $componentsX = 4, int $componentsY = 3): ?array
	{
		if (
			!is_file($path) ||
			!is_readable($path) ||
			!function_exists('imagecreatefromstring') ||
			!function_exists('imagecreatetruecolor') ||
			!function_exists('imagecopyresampled')
		) {
			return null;
		}

		$bytes = @file_get_contents($path);
		if (false === $bytes || '' === $bytes) {
			return null;
		}

		$source = @imagecreatefromstring($bytes);
		if (!$source instanceof \GdImage) {
			return null;
		}

		$srcWidth = imagesx($source);
		$srcHeight = imagesy($source);
		if ($srcWidth < 1 || $srcHeight < 1) {
			return null;
		}

		$scale = min(1.0, self::MAX_SAMPLE_DIMENSION / max($srcWidth, $srcHeight));
		$sampleWidth = max(1, (int) round($srcWidth * $scale));
		$sampleHeight = max(1, (int) round($srcHeight * $scale));

		$canvas = imagecreatetruecolor($sampleWidth, $sampleHeight);
		if (!$canvas instanceof \GdImage) {
			return null;
		}

		imagealphablending($canvas, false);
		imagesavealpha($canvas, true);
		$transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
		if (false !== $transparent) {
			imagefilledrectangle($canvas, 0, 0, $sampleWidth, $sampleHeight, $transparent);
		}
		imagecopyresampled($canvas, $source, 0, 0, 0, 0, $sampleWidth, $sampleHeight, $srcWidth, $srcHeight);

		$pixels = [];
		$opaqueSamples = [];
		$allSamples = [];

		for ($y = 0; $y < $sampleHeight; ++$y) {
			$row = [];
			for ($x = 0; $x < $sampleWidth; ++$x) {
				$colorIndex = imagecolorat($canvas, $x, $y);
				if (false === $colorIndex) {
					$row[$x] = [128, 128, 128];
					continue;
				}
				$rgba = imagecolorsforindex($canvas, $colorIndex);
				$alpha = (int) $rgba['alpha']; // 0 = opaque, 127 = fully transparent
				$r = (int) $rgba['red'];
				$g = (int) $rgba['green'];
				$b = (int) $rgba['blue'];

				if ($alpha > 0) {
					$opacity = (127 - $alpha) / 127.0;
					$blendedR = (int) round($r * $opacity + 255.0 * (1.0 - $opacity));
					$blendedG = (int) round($g * $opacity + 255.0 * (1.0 - $opacity));
					$blendedB = (int) round($b * $opacity + 255.0 * (1.0 - $opacity));
					$row[$x] = [$blendedR, $blendedG, $blendedB];
				} else {
					$row[$x] = [$r, $g, $b];
				}

				$allSamples[] = $row[$x];
				if ($alpha < 120) {
					$opaqueSamples[] = [$r, $g, $b];
				}
			}
			$pixels[$y] = $row;
		}

		$blurhash = $this->encode($pixels, $sampleWidth, $sampleHeight, $componentsX, $componentsY);
		$dominantColor = $this->extractDominantColorFromSamples([] !== $opaqueSamples ? $opaqueSamples : $allSamples);

		return [
			'blurhash' => $blurhash,
			'dominantColor' => $dominantColor,
		];
	}

	/**
	 * @param array<int,mixed> $pixels
	 *
	 * @return list<list<array{0: int, 1: int, 2: int}>>
	 */
	private function normalizePixels(array $pixels, int $width, int $height): array
	{
		if ([] === $pixels) {
			throw new \InvalidArgumentException('Pixel array cannot be empty.');
		}

		// Detect flat list of [r, g, b] tuples vs 2D [$y][$x] = [r, g, b] grid.
		$first = $pixels[0] ?? null;
		$isFlatTuples = is_array($first)
			&& isset($first[0], $first[1], $first[2])
			&& !is_array($first[0])
			&& count($pixels) === $width * $height;

		$grid = [];
		if ($isFlatTuples) {
			$idx = 0;
			for ($y = 0; $y < $height; ++$y) {
				$row = [];
				for ($x = 0; $x < $width; ++$x) {
					$tuple = $pixels[$idx++];
					if (!is_array($tuple) || !isset($tuple[0], $tuple[1], $tuple[2])) {
						throw new \InvalidArgumentException('Each pixel must be an [r, g, b] tuple.');
					}
					$row[] = [
						max(0, min(255, (int) $tuple[0])),
						max(0, min(255, (int) $tuple[1])),
						max(0, min(255, (int) $tuple[2])),
					];
				}
				$grid[] = $row;
			}

			return $grid;
		}

		if (count($pixels) < $height) {
			throw new \InvalidArgumentException('Pixel grid height does not match specified height.');
		}

		for ($y = 0; $y < $height; ++$y) {
			$rawRow = $pixels[$y] ?? null;
			if (!is_array($rawRow) || count($rawRow) < $width) {
				throw new \InvalidArgumentException('Pixel grid row width does not match specified width.');
			}
			$row = [];
			for ($x = 0; $x < $width; ++$x) {
				$tuple = $rawRow[$x] ?? null;
				if (!is_array($tuple) || !isset($tuple[0], $tuple[1], $tuple[2])) {
					throw new \InvalidArgumentException('Each pixel must be an [r, g, b] tuple.');
				}
				$row[] = [
					max(0, min(255, (int) $tuple[0])),
					max(0, min(255, (int) $tuple[1])),
					max(0, min(255, (int) $tuple[2])),
				];
			}
			$grid[] = $row;
		}

		return $grid;
	}

	/**
	 * @param list<array{0: int, 1: int, 2: int}> $samples
	 */
	private function extractDominantColorFromSamples(array $samples): string
	{
		if ([] === $samples) {
			return '#808080';
		}

		/** @var array<int, array{weight: float, count: int, sumR: int, sumG: int, sumB: int}> $buckets */
		$buckets = [];
		foreach ($samples as [$r, $g, $b]) {
			$bucketKey = (($r >> 4) << 8) | (($g >> 4) << 4) | ($b >> 4);
			$max = max($r, $g, $b);
			$min = min($r, $g, $b);
			$saturation = $max > 0 ? ($max - $min) / (float) $max : 0.0;
			$weight = 1.0 + 1.5 * $saturation;

			if (!isset($buckets[$bucketKey])) {
				$buckets[$bucketKey] = [
					'weight' => 0.0,
					'count' => 0,
					'sumR' => 0,
					'sumG' => 0,
					'sumB' => 0,
				];
			}
			$buckets[$bucketKey]['weight'] += $weight;
			++$buckets[$bucketKey]['count'];
			$buckets[$bucketKey]['sumR'] += $r;
			$buckets[$bucketKey]['sumG'] += $g;
			$buckets[$bucketKey]['sumB'] += $b;
		}

		$best = null;
		foreach ($buckets as $bucket) {
			if (null === $best || $bucket['weight'] > $best['weight']) {
				$best = $bucket;
			}
		}

		if (null === $best || 0 === $best['count']) {
			return '#808080';
		}

		$avgR = max(0, min(255, (int) round($best['sumR'] / $best['count'])));
		$avgG = max(0, min(255, (int) round($best['sumG'] / $best['count'])));
		$avgB = max(0, min(255, (int) round($best['sumB'] / $best['count'])));

		return sprintf('#%02x%02x%02x', $avgR, $avgG, $avgB);
	}

	private static function srgbToLinear(int $value): float
	{
		$v = $value / 255.0;
		if ($v <= 0.04045) {
			return $v / 12.92;
		}

		return (($v + 0.055) / 1.055) ** 2.4;
	}

	private static function linearToSrgb(float $value): int
	{
		$v = max(0.0, min(1.0, $value));
		if ($v <= 0.0031308) {
			return (int) floor($v * 12.92 * 255.0 + 0.5);
		}

		return (int) floor((1.055 * ($v ** (1.0 / 2.4)) - 0.055) * 255.0 + 0.5);
	}

	private static function signPow(float $value, float $exponent): float
	{
		return ($value < 0.0 ? -1.0 : 1.0) * (abs($value) ** $exponent);
	}

	private static function encodeDc(float $r, float $g, float $b): int
	{
		$roundedR = self::linearToSrgb($r);
		$roundedG = self::linearToSrgb($g);
		$roundedB = self::linearToSrgb($b);

		return ($roundedR << 16) + ($roundedG << 8) + $roundedB;
	}

	private static function encodeAc(float $r, float $g, float $b, float $maximumValue): int
	{
		$quantR = (int) max(0, min(18, (int) floor(self::signPow($r / $maximumValue, 0.5) * 9.0 + 9.5)));
		$quantG = (int) max(0, min(18, (int) floor(self::signPow($g / $maximumValue, 0.5) * 9.0 + 9.5)));
		$quantB = (int) max(0, min(18, (int) floor(self::signPow($b / $maximumValue, 0.5) * 9.0 + 9.5)));

		return $quantR * 19 * 19 + $quantG * 19 + $quantB;
	}

	private static function encode83(int $value, int $length): string
	{
		$result = '';
		$remaining = max(0, $value);
		for ($i = 0; $i < $length; ++$i) {
			$digit = $remaining % 83;
			$remaining = intdiv($remaining, 83);
			$result = self::BASE83_CHARS[$digit].$result;
		}

		return $result;
	}
}
