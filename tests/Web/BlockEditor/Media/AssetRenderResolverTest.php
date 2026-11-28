<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Media;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Web\BlockEditor\Media\AssetRenderResolver;
use PHPUnit\Framework\TestCase;

final class AssetRenderResolverTest extends TestCase
{
	public function testNormalizeSourceHandlesStructuredAssetLegacyUrlAndBareString(): void
	{
		$token = str_repeat('a', 64);

		$asset = AssetRenderResolver::normalizeSource([
			'source' => 'asset',
			'assetId' => 42,
			'publicToken' => $token,
			'url' => '/assets/'.$token.'/preview',
			'focalPoint' => ['x' => 0.25, 'y' => 0.75],
		]);
		self::assertSame('asset', $asset['mode']);
		self::assertSame(42, $asset['assetId']);
		self::assertSame($token, $asset['publicToken']);
		self::assertSame(['x' => 0.25, 'y' => 0.75], $asset['focalPoint']);

		$legacyObj = AssetRenderResolver::normalizeSource([
			'source' => 'url',
			'url' => 'https://cdn.example.test/hero.jpg',
		]);
		self::assertSame('url', $legacyObj['mode']);
		self::assertNull($legacyObj['assetId']);
		self::assertSame('https://cdn.example.test/hero.jpg', $legacyObj['url']);

		$implicitUrlObj = AssetRenderResolver::normalizeSource([
			'url' => '/media/photo.png',
		]);
		self::assertSame('url', $implicitUrlObj['mode']);
		self::assertSame('/media/photo.png', $implicitUrlObj['url']);

		$bareString = AssetRenderResolver::normalizeSource('/assets/'.$token.'/original');
		self::assertSame('url', $bareString['mode']);
		self::assertSame('/assets/'.$token.'/original', $bareString['url']);

		$unsafeScheme = AssetRenderResolver::normalizeSource('javascript:alert(1)');
		self::assertSame('empty', $unsafeScheme['mode']);

		$empty = AssetRenderResolver::normalizeSource(['source' => 'asset', 'assetId' => null, 'url' => '']);
		self::assertSame('empty', $empty['mode']);
	}

	public function testCollectAssetIdsRecursivelyFindsUniqueIdsAcrossNestedBlocks(): void
	{
		$tree = [
			[
				'id' => 'root-container',
				'type' => 'container',
				'content' => [],
				'children' => [
					[
						'id' => 'img-1',
						'type' => 'image',
						'content' => [
							'source' => ['source' => 'asset', 'assetId' => 11],
						],
					],
					[
						'id' => 'nested',
						'type' => 'container',
						'children' => [
							[
								'id' => 'img-2',
								'type' => 'image',
								'content' => [
									'source' => ['source' => 'asset', 'assetId' => 22],
								],
							],
							[
								'id' => 'img-dup',
								'type' => 'image',
								'content' => [
									'source' => ['source' => 'asset', 'assetId' => 11],
								],
							],
							[
								'id' => 'img-url',
								'type' => 'image',
								'content' => [
									'source' => ['source' => 'url', 'url' => 'https://example.test/x.jpg'],
								],
							],
						],
					],
				],
			],
		];

		self::assertSame([11, 22], AssetRenderResolver::collectAssetIds($tree));
	}

	public function testResolveForRenderOrdersAvifThenWebpAndSortedWidthsWithVersionedUrls(): void
	{
		$token = str_repeat('b', 64);
		$resolver = new AssetRenderResolver();
		$prefetched = [
			7 => [
				'id' => 7,
				'publicToken' => $token,
				'mimeType' => 'image/jpeg',
				'checksum' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
				'visibility' => 'public',
				'width' => 1920,
				'height' => 1080,
				'focalPoint' => ['x' => 0.5, 'y' => 0.35],
				'dominantColor' => '#112233',
				'blurhash' => 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
				'defaultAlt' => 'Mountain sunrise',
				'defaultCaption' => 'Captured at dawn',
				'isExternal' => false,
				'variants' => [
					['key' => 'w1280_webp', 'mimeType' => 'image/webp', 'width' => 1280, 'height' => 720, 'checksum' => '22222222aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'format' => 'webp', 'focalCrop' => false],
					['key' => 'w320_webp', 'mimeType' => 'image/webp', 'width' => 320, 'height' => 180, 'checksum' => '11111111aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'format' => 'webp', 'focalCrop' => false],
					['key' => 'w1280_avif', 'mimeType' => 'image/avif', 'width' => 1280, 'height' => 720, 'checksum' => '44444444aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'format' => 'avif', 'focalCrop' => false],
					['key' => 'w640_avif', 'mimeType' => 'image/avif', 'width' => 640, 'height' => 360, 'checksum' => '33333333aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'format' => 'avif', 'focalCrop' => false],
					['key' => 'w640_jpg', 'mimeType' => 'image/jpeg', 'width' => 640, 'height' => 360, 'checksum' => '55555555aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'format' => 'jpg', 'focalCrop' => false],
					['key' => 'w1280_jpg', 'mimeType' => 'image/jpeg', 'width' => 1280, 'height' => 720, 'checksum' => '66666666aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'format' => 'jpg', 'focalCrop' => false],
					['key' => 'preview', 'mimeType' => 'image/jpeg', 'width' => 1280, 'height' => 720, 'checksum' => '77777777aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'format' => 'jpg', 'focalCrop' => false],
					// Cropped square thumbnail must be excluded from uncropped responsive srcset
					['key' => 'thumbnail', 'mimeType' => 'image/webp', 'width' => 320, 'height' => 320, 'checksum' => '88888888aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'format' => 'webp', 'focalCrop' => true],
				],
			],
		];

		$result = $resolver->resolveForRender(
			['source' => 'asset', 'assetId' => 7],
			['sizes' => '(max-width: 800px) 100vw, 50vw', 'loading' => 'eager', 'fetchpriority' => 'high'],
			$prefetched,
		);

		self::assertNotNull($result);
		self::assertSame('asset', $result['mode']);
		self::assertSame(1920, $result['width']);
		self::assertSame(1080, $result['height']);
		self::assertSame('Mountain sunrise', $result['alt']);
		self::assertSame('Captured at dawn', $result['caption']);
		self::assertSame('50% 35%', $result['objectPosition']);
		self::assertSame('eager', $result['loading']);
		self::assertSame('high', $result['fetchpriority']);
		self::assertSame('async', $result['decoding']);
		self::assertSame('(max-width: 800px) 100vw, 50vw', $result['sizes']);
		self::assertSame('#112233', $result['dominantColor']);
		self::assertSame('LEHV6nWB2yk8pyo0adR*.7kCMdnj', $result['blurhash']);
		self::assertFalse($result['isExternal']);

		self::assertCount(2, $result['sources']);
		self::assertSame('image/avif', $result['sources'][0]['type']);
		self::assertSame(
			'/assets/'.$token.'/w640_avif?v=33333333 640w, /assets/'.$token.'/w1280_avif?v=44444444 1280w',
			$result['sources'][0]['srcset'],
		);
		self::assertSame('image/webp', $result['sources'][1]['type']);
		self::assertSame(
			'/assets/'.$token.'/w320_webp?v=11111111 320w, /assets/'.$token.'/w1280_webp?v=22222222 1280w',
			$result['sources'][1]['srcset'],
		);
		self::assertSame('/assets/'.$token.'/preview?v=77777777', $result['src']);
		self::assertSame(
			'/assets/'.$token.'/w640_jpg?v=55555555 640w, /assets/'.$token.'/w1280_jpg?v=66666666 1280w',
			$result['srcset'],
		);
	}

	public function testResolveForRenderHandlesDecorativeAltFocalPrecedenceAndPrivateVisibility(): void
	{
		$token = str_repeat('c', 64);
		$resolver = new AssetRenderResolver();
		$prefetched = [
			9 => [
				'id' => 9,
				'publicToken' => $token,
				'mimeType' => 'image/png',
				'checksum' => null,
				'visibility' => 'public',
				'width' => 800,
				'height' => 600,
				'focalPoint' => ['x' => 0.2, 'y' => 0.8],
				'defaultAlt' => 'Asset alt',
				'defaultCaption' => 'Asset caption',
				'isExternal' => false,
				'variants' => [],
			],
			10 => [
				'id' => 10,
				'publicToken' => str_repeat('d', 64),
				'mimeType' => 'image/png',
				'checksum' => null,
				'visibility' => 'private',
				'width' => 400,
				'height' => 300,
				'focalPoint' => ['x' => 0.5, 'y' => 0.5],
				'defaultAlt' => 'Private',
				'defaultCaption' => null,
				'isExternal' => false,
				'variants' => [],
			],
		];

		$decorative = $resolver->resolveForRender(
			['source' => 'asset', 'assetId' => 9, 'focalPoint' => ['x' => 0.6, 'y' => 0.4]],
			['alt' => 'Block alt', 'decorative' => true, 'focalPointOverride' => ['x' => 0.1, 'y' => 0.9]],
			$prefetched,
		);
		self::assertNotNull($decorative);
		self::assertSame('', $decorative['alt']);
		self::assertSame('10% 90%', $decorative['objectPosition']);

		$privateBlocked = $resolver->resolveForRender(['source' => 'asset', 'assetId' => 10], [], $prefetched, null, false);
		self::assertNull($privateBlocked);

		$privateAllowed = $resolver->resolveForRender(['source' => 'asset', 'assetId' => 10], [], $prefetched, null, true);
		self::assertNotNull($privateAllowed);
		self::assertSame('/assets/'.str_repeat('d', 64).'/original', $privateAllowed['src']);
	}

	public function testPrefetchForSiteUsesTwoBatchedQueriesAndSkipsPrivateAssetsWhenPublic(): void
	{
		$site = new Site();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($site, 5);

		$publicAsset = new UploadedDigitalAsset('Hero', 'image/jpeg', str_repeat('e', 64));
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($publicAsset, 101);
		$publicAsset->setVisibility('public')->setWidth(1600)->setHeight(900)->setDefaultAlt('Hero image');

		$privateAsset = new UploadedDigitalAsset('Secret', 'image/jpeg', str_repeat('f', 64));
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($privateAsset, 102);
		$privateAsset->setVisibility('private');

		$variant = new DigitalAssetVariant($publicAsset, 'w640_webp', 'storage/k', 'image/webp', 12000, 640, 360, 'abcdef1200000000000000000000000000000000000000000000000000000000', 'webp', 80, false);

		$assetRepo = $this->createMock(DigitalAssetRepository::class);
		$assetRepo->expects(self::once())
			->method('findAccessibleToSiteByIds')
			->with([101, 102], $site)
			->willReturn([$publicAsset, $privateAsset]);

		$variantRepo = $this->createMock(DigitalAssetVariantRepository::class);
		$variantRepo->expects(self::once())
			->method('findByAssetIds')
			->with([101])
			->willReturn([$variant]);

		$resolver = new AssetRenderResolver($assetRepo, $variantRepo);
		$prefetched = $resolver->prefetchForSite([101, 102, 101], $site, false);

		self::assertArrayHasKey(101, $prefetched);
		self::assertArrayNotHasKey(102, $prefetched);
		self::assertSame('Hero image', $prefetched[101]['defaultAlt']);
		self::assertCount(1, $prefetched[101]['variants']);
	}
}
