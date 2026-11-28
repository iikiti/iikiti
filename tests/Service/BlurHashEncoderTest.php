<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Service\BlurHashEncoder;
use PHPUnit\Framework\TestCase;

final class BlurHashEncoderTest extends TestCase
{
	private BlurHashEncoder $encoder;

	protected function setUp(): void
	{
		$this->encoder = new BlurHashEncoder();
	}

	public function testEncodesSolidColorPixelGridAndFlatTuplesIdentically(): void
	{
		$width = 8;
		$height = 6;
		$grid = [];
		$flat = [];
		for ($y = 0; $y < $height; ++$y) {
			$row = [];
			for ($x = 0; $x < $width; ++$x) {
				$row[] = [42, 128, 220];
				$flat[] = [42, 128, 220];
			}
			$grid[] = $row;
		}

		$hashGrid = $this->encoder->encode($grid, $width, $height, 4, 3);
		$hashFlat = $this->encoder->encode($flat, $width, $height, 4, 3);

		self::assertSame($hashGrid, $hashFlat);
		// Length = 1 (size) + 1 (maxAC) + 4 (DC) + 2 * (4*3 - 1) = 28
		self::assertSame(28, strlen($hashGrid));
		self::assertMatchesRegularExpression('/^[0-9A-Za-z#\$%\*\+,\-\.:;=\?@\[\]\^_\{\|\}~]{28}$/', $hashGrid);
	}

	public function testEncodesGradientWithDistinctComponents(): void
	{
		$width = 16;
		$height = 12;
		$solid = [];
		$gradient = [];
		for ($y = 0; $y < $height; ++$y) {
			$solidRow = [];
			$gradRow = [];
			for ($x = 0; $x < $width; ++$x) {
				$solidRow[] = [128, 128, 128];
				$gradRow[] = [(int) round($x * 255 / 15), (int) round($y * 255 / 11), 64];
			}
			$solid[] = $solidRow;
			$gradient[] = $gradRow;
		}

		$solidHash = $this->encoder->encode($solid, $width, $height, 4, 3);
		$gradientHash = $this->encoder->encode($gradient, $width, $height, 4, 3);

		self::assertNotSame($solidHash, $gradientHash);
		self::assertSame(28, strlen($gradientHash));
	}

	public function testExtractsExactSolidAndVibrantDominantColor(): void
	{
		$solid = array_fill(0, 4, array_fill(0, 4, [230, 57, 70]));
		self::assertSame('#e63946', $this->encoder->extractDominantColor($solid, 4, 4));

		// 60% neutral gray, 40% vivid orange -> saturation weighting highlights the vivid color.
		$mixed = [];
		for ($y = 0; $y < 10; ++$y) {
			$row = [];
			for ($x = 0; $x < 10; ++$x) {
				$row[] = $x < 4 ? [255, 120, 0] : [130, 130, 130];
			}
			$mixed[] = $row;
		}
		self::assertSame('#ff7800', $this->encoder->extractDominantColor($mixed, 10, 10));
	}

	public function testEncodesFromGdPngFileAndGracefullyHandlesInvalidFiles(): void
	{
		if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
			self::markTestSkipped('GD PNG support is required for this test.');
		}

		$path = sys_get_temp_dir().'/iikiti-blurhash-'.bin2hex(random_bytes(6)).'.png';
		$badPath = sys_get_temp_dir().'/iikiti-blurhash-bad-'.bin2hex(random_bytes(6)).'.bin';
		try {
			$image = imagecreatetruecolor(64, 48);
			self::assertInstanceOf(\GdImage::class, $image);
			$blue = imagecolorallocate($image, 30, 100, 210);
			self::assertIsInt($blue);
			imagefilledrectangle($image, 0, 0, 64, 48, $blue);
			imagepng($image, $path);

			$result = $this->encoder->encodeFromImageFile($path);
			self::assertIsArray($result);
			self::assertSame(28, strlen($result['blurhash']));
			self::assertSame('#1e64d2', $result['dominantColor']);

			file_put_contents($badPath, 'not-an-image');
			self::assertNull($this->encoder->encodeFromImageFile($badPath));
			self::assertNull($this->encoder->encodeFromImageFile($badPath.'.missing'));
		} finally {
			@unlink($path);
			@unlink($badPath);
		}
	}

	public function testRejectsInvalidComponentBounds(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->encoder->encode([[[0, 0, 0]]], 1, 1, 10, 3);
	}
}
