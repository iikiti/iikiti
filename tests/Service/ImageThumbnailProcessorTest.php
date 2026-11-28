<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Service\ImageThumbnailProcessor;
use iikiti\CMS\Storage\LocalAssetStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ImageThumbnailProcessorTest extends TestCase
{
	private string $directory;
	private Filesystem $filesystem;

	protected function setUp(): void
	{
		$this->directory = dirname(__DIR__, 2).'/var/test-image-thumbnails-'.bin2hex(random_bytes(8));
		$this->filesystem = new Filesystem();
		$this->filesystem->mkdir($this->directory, 0700);
	}

	protected function tearDown(): void
	{
		$this->filesystem->remove($this->directory);
	}

	public function testComputeFocalCropGeometryCoversCenteredEdgeClampedAndPortraitSources(): void
	{
		// Landscape 1000x500 cropped to square 400x400, centered focal point.
		self::assertSame(
			['width' => 500, 'height' => 500, 'x' => 250, 'y' => 0],
			ImageThumbnailProcessor::computeFocalCropGeometry(1000, 500, 400, 400, 0.5, 0.5),
		);

		// Clamped to left edge when focal point is near x=0.
		self::assertSame(
			['width' => 500, 'height' => 500, 'x' => 0, 'y' => 0],
			ImageThumbnailProcessor::computeFocalCropGeometry(1000, 500, 400, 400, 0.05, 0.5),
		);

		// Clamped to right edge when focal point is near x=1.
		self::assertSame(
			['width' => 500, 'height' => 500, 'x' => 500, 'y' => 0],
			ImageThumbnailProcessor::computeFocalCropGeometry(1000, 500, 400, 400, 0.95, 0.5),
		);

		// Interior shift to x=0.7 -> center 700 -> x = 700 - 250 = 450.
		self::assertSame(
			['width' => 500, 'height' => 500, 'x' => 450, 'y' => 0],
			ImageThumbnailProcessor::computeFocalCropGeometry(1000, 500, 400, 400, 0.7, 0.5),
		);

		// Portrait 600x1200 cropped to 16:9 with face near top (focalY = 0.2).
		$portrait = ImageThumbnailProcessor::computeFocalCropGeometry(600, 1200, 1600, 900, 0.5, 0.2);
		self::assertSame(600, $portrait['width']);
		self::assertSame(338, $portrait['height']);
		self::assertSame(0, $portrait['x']);
		self::assertSame(71, $portrait['y']);
		self::assertLessThanOrEqual(1200, $portrait['y'] + $portrait['height']);

		// Exact aspect ratio match keeps full frame.
		self::assertSame(
			['width' => 800, 'height' => 600, 'x' => 0, 'y' => 0],
			ImageThumbnailProcessor::computeFocalCropGeometry(800, 600, 400, 300, 0.2, 0.8),
		);
	}

	public function testParsesImageMagickFormatListForWritableEncodersOnly(): void
	{
		$sampleOutput = <<<'TXT'
   Format  Mode  Description
-------------------------------------------------------------------------------
     AVIF  rw+   AV1 Image File Format (1.16.1)
     WEBP* rw-   WebP Image Format (libwebp 1.3.2)
     JPEG* rw-   Joint Photographic Experts Group JFIF format
      PNG* rw-   Portable Network Graphics
      CR2  r--   Canon Digital Camera Raw Image Format
TXT;

		$formats = ImageThumbnailProcessor::parseFormatListOutput($sampleOutput);

		self::assertContains('avif', $formats);
		self::assertContains('webp', $formats);
		self::assertContains('jpeg', $formats);
		self::assertContains('jpg', $formats);
		self::assertContains('png', $formats);
		self::assertNotContains('cr2', $formats);
	}

	public function testPlanVariantsEnforcesNoUpscaleLadderAndGracefulAvifWebpFallback(): void
	{
		$storage = new LocalAssetStorage($this->directory, $this->filesystem);
		$variantsRepo = $this->createMock(DigitalAssetVariantRepository::class);
		$entityManager = $this->createMock(EntityManagerInterface::class);

		// Case 1: ImageMagick supports only WebP + JPEG (no AVIF delegate).
		$processorNoAvif = new ImageThumbnailProcessor(
			$storage,
			$variantsRepo,
			$entityManager,
			$this->filesystem,
			formatDetector: static fn (): array => ['jpeg', 'png', 'webp'],
		);

		$jobs = $processorNoAvif->planVariants(800, 600, 'jpg', [
			'variants' => ImageThumbnailProcessor::DEFAULT_NAMED_VARIANTS,
			'responsive_widths' => [320, 640, 960, 1280, 1920],
			'formats' => ['avif', 'webp', 'original'],
		]);

		$names = array_column($jobs, 'name');
		// Legacy named variants still present.
		self::assertContains('thumbnail', $names);
		self::assertContains('preview', $names);
		// Responsive widths strictly <= 800 (no upscaling to 960/1280/1920), AVIF skipped gracefully.
		self::assertContains('w320_webp', $names);
		self::assertContains('w320_jpg', $names);
		self::assertContains('w640_webp', $names);
		self::assertContains('w640_jpg', $names);
		self::assertNotContains('w320_avif', $names);
		self::assertNotContains('w640_avif', $names);
		self::assertNotContains('w960_webp', $names);
		self::assertNotContains('w960_jpg', $names);
		self::assertNotContains('w1280_jpg', $names);
		self::assertNotContains('w1920_jpg', $names);

		// Case 2: Neither AVIF nor WebP supported -> falls back to original format only.
		$processorOriginalOnly = new ImageThumbnailProcessor(
			$storage,
			$variantsRepo,
			$entityManager,
			$this->filesystem,
			formatDetector: static fn (): array => ['jpeg', 'png'],
		);

		$fallbackJobs = $processorOriginalOnly->planVariants(1000, 750, 'jpg', [
			'variants' => [],
			'responsive_widths' => [320, 640, 960, 1280],
			'formats' => ['avif', 'webp', 'original'],
		]);

		self::assertSame(['w320_jpg', 'w640_jpg', 'w960_jpg'], array_column($fallbackJobs, 'name'));
	}

	public function testBuildCommandPreservesIccProfilesAndAppliesFocalCrop(): void
	{
		$storage = new LocalAssetStorage($this->directory, $this->filesystem);
		$processor = new ImageThumbnailProcessor(
			$storage,
			$this->createMock(DigitalAssetVariantRepository::class),
			$this->createMock(EntityManagerInterface::class),
			$this->filesystem,
			formatDetector: static fn (): array => ['avif', 'webp', 'jpeg', 'png'],
		);

		$jobs = $processor->planVariants(1200, 600, 'jpg', [
			'variants' => [
				['name' => 'card_square', 'width' => 400, 'height' => 400, 'crop' => true, 'format' => 'webp', 'quality' => 85],
			],
			'responsive_widths' => [],
		], focalX: 0.75, focalY: 0.5);

		self::assertCount(1, $jobs);
		self::assertTrue($jobs[0]['focalCrop']);
		self::assertSame('webp', $jobs[0]['format']);
		self::assertSame(85, $jobs[0]['quality']);
		self::assertSame(['width' => 600, 'height' => 600, 'x' => 600, 'y' => 0], $jobs[0]['cropGeometry']);

		$command = $processor->buildCommand('/tmp/src.jpg', '/tmp/out.webp', $jobs[0]);

		self::assertContains('-auto-orient', $command);
		self::assertContains('-crop', $command);
		self::assertContains('600x600+600+0', $command);
		self::assertContains('+repage', $command);
		self::assertContains('+profile', $command);
		self::assertContains('!icc,*', $command);
		self::assertContains('+set', $command);
		self::assertContains('comment', $command);
		self::assertContains('-quality', $command);
		self::assertContains('85', $command);
		// Must never use -strip, which deletes ICC color profiles.
		self::assertNotContains('-strip', $command);
	}

	public function testProcessPersistsVariantFormatQualityAndFocalCropMetadata(): void
	{
		if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
			self::markTestSkipped('GD PNG support is required.');
		}

		$storage = new LocalAssetStorage($this->directory, $this->filesystem);
		$key = 'ab/'.str_repeat('5', 64).'.png';
		$path = $storage->pathFor($key);
		$this->filesystem->mkdir(dirname($path), 0700);

		$sourceImg = imagecreatetruecolor(800, 400);
		self::assertInstanceOf(\GdImage::class, $sourceImg);
		imagepng($sourceImg, $path);

		$asset = new UploadedDigitalAsset('Hero', 'image/png', str_repeat('f', 64));
		$asset->setStorageKey($key);
		$asset->setFocalPoint(0.25, 0.5);

		$persisted = [];
		$variantsRepo = $this->createMock(DigitalAssetVariantRepository::class);
		$variantsRepo->method('findByAssetAndKey')->willReturn(null);
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
			if ($entity instanceof DigitalAssetVariant) {
				$persisted[$entity->getKey()] = $entity;
			}
		});
		$entityManager->expects(self::atLeastOnce())->method('flush');

		$commandsExecuted = [];
		$processor = new ImageThumbnailProcessor(
			$storage,
			$variantsRepo,
			$entityManager,
			$this->filesystem,
			formatDetector: static fn (): array => ['avif', 'webp', 'png'],
			commandRunner: static function (array $command) use (&$commandsExecuted): void {
				$commandsExecuted[] = $command;
				$outputPath = $command[count($command) - 1];
				if (str_ends_with($outputPath, '.avif')) {
					file_put_contents($outputPath, 'FAKEAVIFDATA');
				} else {
					$out = imagecreatetruecolor(320, 160);
					self::assertInstanceOf(\GdImage::class, $out);
					imagepng($out, $outputPath);
				}
			},
		);

		$workflow = new AssetWorkflowDefinition('image-thumbnails', 'Thumbnails', 'image_thumbnail', 'image/*', [], [
			'variants' => [
				['name' => 'thumbnail', 'width' => 320, 'height' => 320],
				['name' => 'hero_crop', 'width' => 320, 'aspect_ratio' => '16:9'],
			],
			'responsive_widths' => [320, 640, 1280],
			'formats' => ['avif', 'webp', 'original'],
		]);

		$processor->process($asset, $workflow);

		self::assertArrayHasKey('thumbnail', $persisted);
		self::assertFalse($persisted['thumbnail']->isFocalCrop());
		self::assertSame('png', $persisted['thumbnail']->getFormat());

		self::assertArrayHasKey('hero_crop', $persisted);
		self::assertTrue($persisted['hero_crop']->isFocalCrop());
		self::assertSame('png', $persisted['hero_crop']->getFormat());

		self::assertArrayHasKey('w320_avif', $persisted);
		self::assertSame('avif', $persisted['w320_avif']->getFormat());
		self::assertSame(65, $persisted['w320_avif']->getQuality());

		self::assertArrayHasKey('w640_webp', $persisted);
		self::assertSame('webp', $persisted['w640_webp']->getFormat());
		self::assertSame(80, $persisted['w640_webp']->getQuality());

		self::assertArrayHasKey('w640_png', $persisted);
		self::assertArrayNotHasKey('w1280_avif', $persisted);
		self::assertNotEmpty($commandsExecuted);
	}

	public function testProcessUpdatesExistingVariantInPlaceWithoutRemoveOrPersist(): void
	{
		if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
			self::markTestSkipped('GD PNG support is required.');
		}

		$storage = new LocalAssetStorage($this->directory, $this->filesystem);
		$sourceKey = 'ab/'.str_repeat('6', 64).'.png';
		$sourcePath = $storage->pathFor($sourceKey);
		$this->filesystem->mkdir(dirname($sourcePath), 0700);
		$sourceImg = imagecreatetruecolor(640, 480);
		self::assertInstanceOf(\GdImage::class, $sourceImg);
		imagepng($sourceImg, $sourcePath);

		$oldVariantKey = 'cd/'.str_repeat('7', 64).'.png';
		$oldVariantPath = $storage->pathFor($oldVariantKey);
		$this->filesystem->mkdir(dirname($oldVariantPath), 0700);
		file_put_contents($oldVariantPath, 'old-variant-bytes');

		$asset = new UploadedDigitalAsset('Banner', 'image/png', str_repeat('e', 64));
		$asset->setStorageKey($sourceKey);

		$existingVariant = new DigitalAssetVariant($asset, 'thumbnail', $oldVariantKey, 'image/png', 17, 100, 100, str_repeat('0', 64), 'png', null, false);

		$variantsRepo = $this->createMock(DigitalAssetVariantRepository::class);
		$variantsRepo->method('findByAssetAndKey')->willReturn($existingVariant);

		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::never())->method('remove');
		$entityManager->expects(self::never())->method('persist');
		$entityManager->expects(self::once())->method('flush');

		$processor = new ImageThumbnailProcessor(
			$storage,
			$variantsRepo,
			$entityManager,
			$this->filesystem,
			formatDetector: static fn (): array => ['png'],
			commandRunner: static function (array $command): void {
				$outputPath = $command[count($command) - 1];
				$out = imagecreatetruecolor(320, 240);
				self::assertInstanceOf(\GdImage::class, $out);
				imagepng($out, $outputPath);
			},
		);

		$workflow = new AssetWorkflowDefinition('image-thumbnails', 'Thumbnails', 'image_thumbnail', 'image/*', [], [
			'variants' => [
				['name' => 'thumbnail', 'width' => 320, 'height' => 320],
			],
			'responsive_widths' => [],
		]);

		$processor->process($asset, $workflow);

		self::assertNotSame($oldVariantKey, $existingVariant->getStorageKey());
		self::assertSame(320, $existingVariant->getWidth());
		self::assertSame(240, $existingVariant->getHeight());
		self::assertFalse($storage->exists($oldVariantKey));
		self::assertTrue($storage->exists($existingVariant->getStorageKey()));
	}
}
