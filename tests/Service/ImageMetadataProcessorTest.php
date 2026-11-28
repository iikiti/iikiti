<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Service\BlurHashEncoder;
use iikiti\CMS\Service\ImageMetadataProcessor;
use iikiti\CMS\Storage\LocalAssetStorage;
use iikiti\CMS\Value\LocalizedValue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ImageMetadataProcessorTest extends TestCase
{
	private string $directory;
	private Filesystem $filesystem;

	protected function setUp(): void
	{
		$this->directory = dirname(__DIR__, 2).'/var/test-image-metadata-'.bin2hex(random_bytes(8));
		$this->filesystem = new Filesystem();
		$this->filesystem->mkdir($this->directory, 0700);
	}

	protected function tearDown(): void
	{
		$this->filesystem->remove($this->directory);
	}

	public function testKeyAndMimePatternSupport(): void
	{
		$storage = new LocalAssetStorage($this->directory, $this->filesystem);
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$processor = new ImageMetadataProcessor($storage, $entityManager, new BlurHashEncoder());

		self::assertSame('image_metadata', $processor->getKey());
		self::assertTrue($processor->supportsMimePattern('image/*'));
		self::assertFalse($processor->supportsMimePattern('application/pdf'));
	}

	public function testProcessesDimensionsBlurHashDominantColorAndXmpMetadata(): void
	{
		if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
			self::markTestSkipped('GD JPEG support is required.');
		}

		$storage = new LocalAssetStorage($this->directory, $this->filesystem);
		$key = 'ab/'.str_repeat('1', 64).'.jpg';
		$path = $storage->pathFor($key);
		$this->filesystem->mkdir(dirname($path), 0700);

		$xmp = <<<'XML'
<x:xmpmeta xmlns:x="adobe:ns:meta/">
	<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
			 xmlns:dc="http://purl.org/dc/elements/1.1/"
			 xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/">
		<rdf:Description photoshop:Headline="Mountain Dawn">
			<dc:description>
				<rdf:Alt>
					<rdf:li xml:lang="x-default">Sunrise over misty alpine peaks</rdf:li>
					<rdf:li xml:lang="en">Sunrise over misty alpine peaks</rdf:li>
					<rdf:li xml:lang="fr">Lever de soleil sur les sommets alpins</rdf:li>
				</rdf:Alt>
			</dc:description>
			<dc:title>
				<rdf:Alt>
					<rdf:li xml:lang="x-default">Alpine Sunrise</rdf:li>
				</rdf:Alt>
			</dc:title>
			<dc:creator>
				<rdf:Seq>
					<rdf:li>Jane Photographer</rdf:li>
				</rdf:Seq>
			</dc:creator>
			<dc:rights>
				<rdf:Alt>
					<rdf:li xml:lang="x-default">CC-BY-4.0</rdf:li>
				</rdf:Alt>
			</dc:rights>
			<dc:subject>
				<rdf:Bag>
					<rdf:li>mountains</rdf:li>
					<rdf:li>sunrise</rdf:li>
				</rdf:Bag>
			</dc:subject>
		</rdf:Description>
	</rdf:RDF>
</x:xmpmeta>
XML;

		$jpeg = $this->createJpegWithAppSegments(120, 80, [40, 160, 90], [
			$this->buildXmpApp1Segment($xmp),
		]);
		file_put_contents($path, $jpeg);

		$asset = new UploadedDigitalAsset('Alpine', 'image/jpeg', str_repeat('a', 64));
		$asset->setStorageKey($key);

		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::once())->method('flush');

		$processor = new ImageMetadataProcessor($storage, $entityManager, new BlurHashEncoder());
		$workflow = new AssetWorkflowDefinition('image-metadata', 'Image metadata', 'image_metadata', 'image/*');
		$processor->process($asset, $workflow);

		self::assertSame(120, $asset->getWidth());
		self::assertSame(80, $asset->getHeight());
		self::assertNotNull($asset->getBlurhash());
		self::assertSame(28, strlen($asset->getBlurhash()));
		self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', (string) $asset->getDominantColor());

		$metadata = $asset->getMediaMetadata();
		self::assertIsArray($metadata);
		self::assertSame('Sunrise over misty alpine peaks', $metadata['xmp']['description'] ?? null);
		self::assertSame('Mountain Dawn', $metadata['xmp']['headline'] ?? null);
		self::assertSame('Alpine Sunrise', $metadata['xmp']['title'] ?? null);
		self::assertSame('Jane Photographer', $metadata['xmp']['creator'] ?? null);
		self::assertSame('CC-BY-4.0', $metadata['xmp']['rights'] ?? null);
		self::assertSame(['mountains', 'sunrise'], $metadata['xmp']['subject'] ?? null);

		// Multi-lingual XMP dc:description populates default_alt as LocalizedValue, headline populates default_caption.
		$defaultAlt = $asset->getDefaultAlt();
		self::assertInstanceOf(LocalizedValue::class, $defaultAlt);
		self::assertSame([
			'en' => 'Sunrise over misty alpine peaks',
			'fr' => 'Lever de soleil sur les sommets alpins',
		], $defaultAlt->getTranslations());
		self::assertSame('Mountain Dawn', $asset->getDefaultCaption());
	}

	public function testDoesNotOverwriteExistingDefaultAltOrCaption(): void
	{
		if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
			self::markTestSkipped('GD JPEG support is required.');
		}

		$storage = new LocalAssetStorage($this->directory, $this->filesystem);
		$key = 'cd/'.str_repeat('2', 64).'.jpg';
		$path = $storage->pathFor($key);
		$this->filesystem->mkdir(dirname($path), 0700);

		$xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"><rdf:Description photoshop:Headline="Embedded Headline"/></rdf:RDF></x:xmpmeta>';
		file_put_contents($path, $this->createJpegWithAppSegments(64, 64, [200, 100, 50], [$this->buildXmpApp1Segment($xmp)]));

		$asset = new UploadedDigitalAsset('Custom', 'image/jpeg', str_repeat('b', 64));
		$asset->setStorageKey($key);
		$asset->setDefaultAlt('Editorial alt text');
		$asset->setDefaultCaption('Editorial caption');

		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::once())->method('flush');

		$processor = new ImageMetadataProcessor($storage, $entityManager, new BlurHashEncoder());
		$processor->process($asset, new AssetWorkflowDefinition('image-metadata', 'Image metadata', 'image_metadata', 'image/*'));

		self::assertSame('Editorial alt text', $asset->getDefaultAlt());
		self::assertSame('Editorial caption', $asset->getDefaultCaption());
	}

	public function testSwapsDimensionsForExifOrientation6AndStripsGpsByDefault(): void
	{
		if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg') || !function_exists('exif_read_data')) {
			self::markTestSkipped('GD JPEG and EXIF extensions are required.');
		}

		$storage = new LocalAssetStorage($this->directory, $this->filesystem);
		$key = 'ef/'.str_repeat('3', 64).'.jpg';
		$path = $storage->pathFor($key);
		$this->filesystem->mkdir(dirname($path), 0700);

		// Raw image is 160x100 with EXIF Orientation=6 (90° CW) and a GPS IFD.
		$exifSegment = $this->buildExifApp1Segment(orientation: 6, includeGps: true);
		file_put_contents($path, $this->createJpegWithAppSegments(160, 100, [80, 90, 100], [$exifSegment]));

		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::exactly(2))->method('flush');
		$processor = new ImageMetadataProcessor($storage, $entityManager, new BlurHashEncoder());

		// Default: privacy-first GPS stripping.
		$assetPrivate = new UploadedDigitalAsset('Rotated', 'image/jpeg', str_repeat('c', 64));
		$assetPrivate->setStorageKey($key);
		$processor->process($assetPrivate, new AssetWorkflowDefinition('image-metadata', 'Image metadata', 'image_metadata', 'image/*'));

		self::assertSame(100, $assetPrivate->getWidth());
		self::assertSame(160, $assetPrivate->getHeight());
		$metaPrivate = $assetPrivate->getMediaMetadata();
		self::assertSame(6, $metaPrivate['orientation'] ?? null);
		self::assertArrayNotHasKey('gps', $metaPrivate['exif'] ?? []);

		// Explicit opt-in: preserve_gps = true.
		$assetWithGps = new UploadedDigitalAsset('Rotated GPS', 'image/jpeg', str_repeat('d', 64));
		$assetWithGps->setStorageKey($key);
		$processor->process($assetWithGps, new AssetWorkflowDefinition('image-metadata', 'Image metadata', 'image_metadata', 'image/*', [], ['preserve_gps' => true]));

		$metaWithGps = $assetWithGps->getMediaMetadata();
		self::assertArrayHasKey('gps', $metaWithGps['exif'] ?? []);
		self::assertSame('N', $metaWithGps['exif']['gps']['GPSLatitudeRef'] ?? null);
	}

	public function testIgnoresXmpPacketsContainingDoctypeDeclarations(): void
	{
		if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
			self::markTestSkipped('GD JPEG support is required.');
		}

		$storage = new LocalAssetStorage($this->directory, $this->filesystem);
		$key = '12/'.str_repeat('4', 64).'.jpg';
		$path = $storage->pathFor($key);
		$this->filesystem->mkdir(dirname($path), 0700);

		$maliciousXmp = '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"><rdf:Description photoshop:Headline="&xxe;"/></rdf:RDF></x:xmpmeta>';
		file_put_contents($path, $this->createJpegWithAppSegments(40, 40, [10, 20, 30], [$this->buildXmpApp1Segment($maliciousXmp)]));

		$asset = new UploadedDigitalAsset('Unsafe', 'image/jpeg', str_repeat('e', 64));
		$asset->setStorageKey($key);

		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::once())->method('flush');

		$processor = new ImageMetadataProcessor($storage, $entityManager, new BlurHashEncoder());
		$processor->process($asset, new AssetWorkflowDefinition('image-metadata', 'Image metadata', 'image_metadata', 'image/*'));

		self::assertNull($asset->getDefaultAlt());
		self::assertArrayNotHasKey('xmp', $asset->getMediaMetadata() ?? []);
	}

	/**
	 * @param array{0: int, 1: int, 2: int} $rgb
	 * @param list<string>                  $segments
	 */
	private function createJpegWithAppSegments(int $width, int $height, array $rgb, array $segments): string
	{
		$image = imagecreatetruecolor($width, $height);
		self::assertInstanceOf(\GdImage::class, $image);
		$color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
		self::assertIsInt($color);
		imagefilledrectangle($image, 0, 0, $width, $height, $color);

		ob_start();
		imagejpeg($image, null, 90);
		$jpeg = (string) ob_get_clean();

		// Insert custom APP segments immediately after the JPEG SOI marker (0xFFD8).
		return substr($jpeg, 0, 2).implode('', $segments).substr($jpeg, 2);
	}

	private function buildXmpApp1Segment(string $xmp): string
	{
		$payload = "http://ns.adobe.com/xap/1.0/\0".$xmp;

		return "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
	}

	/**
	 * Builds a minimal little-endian TIFF EXIF APP1 segment with Orientation and optional GPS IFD.
	 */
	private function buildExifApp1Segment(int $orientation, bool $includeGps): string
	{
		// TIFF header: "II" + 42 + offset 8 to IFD0
		$tiff = "II".pack('vV', 42, 8);
		$entries = [];
		// Tag 0x0112 (Orientation), type SHORT (3), count 1, value $orientation
		$entries[] = pack('vvVV', 0x0112, 3, 1, $orientation);

		$gpsIfd = '';
		if ($includeGps) {
			// IFD0 has 2 entries: 8 (header) + 2 (count) + 2*12 (entries) + 4 (next IFD) = 38
			$gpsOffset = 38;
			// Tag 0x8825 (GPSInfoIFDPointer), type LONG (4), count 1, value $gpsOffset
			$entries[] = pack('vvVV', 0x8825, 4, 1, $gpsOffset);
			// GPS IFD: 1 entry -> Tag 0x0001 (GPSLatitudeRef), type ASCII (2), count 2, "N\0\0\0"
			$gpsIfd = pack('v', 1).pack('vvV', 0x0001, 2, 2)."N\0\0\0".pack('V', 0);
		}

		$ifd0 = pack('v', count($entries)).implode('', $entries).pack('V', 0);
		$payload = "Exif\0\0".$tiff.$ifd0.$gpsIfd;

		return "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
	}
}
