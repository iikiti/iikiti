<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Plugin\ContentOptimization;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use iikiti\Extension\ContentOptimization\Service\PageSeoSettingsNormalizer;
use iikiti\Extension\ContentOptimization\Service\SeoMetadataResolver;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/plugins/iikiti-content-optimization/src/Service/ContentOptimizationSettings.php';
require_once dirname(__DIR__, 3).'/plugins/iikiti-content-optimization/src/Service/PageSeoSettingsNormalizer.php';
require_once dirname(__DIR__, 3).'/plugins/iikiti-content-optimization/src/Service/SeoMetadataResolver.php';

final class SeoMediaAssetTest extends TestCase
{
	public function testNormalizerAcceptsStructuredDamMediaAndUrlObjects(): void
	{
		$token = str_repeat('a', 64);
		$normalizer = new PageSeoSettingsNormalizer();

		$normalized = $normalizer->normalize([
			'iikiti_content_optimization_og_image' => [
				'source' => 'asset',
				'assetId' => 42,
				'publicToken' => $token,
				'url' => '/assets/'.$token.'/preview',
			],
			'iikiti_content_optimization_twitter_image' => [
				'source' => 'url',
				'url' => 'https://cdn.example.test/twitter.png',
			],
		]);

		self::assertSame([
			'source' => 'asset',
			'assetId' => 42,
			'publicToken' => $token,
			'url' => '/assets/'.$token.'/preview',
		], $normalized['iikiti_content_optimization_og_image']);
		self::assertSame([
			'source' => 'url',
			'url' => 'https://cdn.example.test/twitter.png',
		], $normalized['iikiti_content_optimization_twitter_image']);

		// Idempotency check
		self::assertSame($normalized, $normalizer->normalize($normalized));
	}

	public function testNormalizerClearsEmptyMediaObjectsToEmptyString(): void
	{
		$normalizer = new PageSeoSettingsNormalizer();
		$normalized = $normalizer->normalize([
			'iikiti_content_optimization_og_image' => ['source' => 'asset', 'assetId' => null],
			'iikiti_content_optimization_twitter_image' => ['source' => 'url', 'url' => ''],
		]);

		self::assertSame('', $normalized['iikiti_content_optimization_og_image']);
		self::assertSame('', $normalized['iikiti_content_optimization_twitter_image']);
	}

	public function testContentOptimizationSettingsAcceptsDamDefaultSocialImageAndResolvesAtomically(): void
	{
		$settings = new ContentOptimizationSettings();
		$input = json_decode((string) json_encode([
			'version' => 1,
			'seo' => [
				'titleTemplate' => '{pageTitle} | {siteName}',
				'defaultDescription' => 'Default',
				'defaultSocialImage' => ['source' => 'asset', 'assetId' => 15],
				'defaultRobots' => ['index' => 'index', 'follow' => 'follow'],
			],
			'sitemap' => ['enabled' => true],
			'crawlers' => ['aiAccess' => 'allow'],
			'analytics' => [
				'ga4' => ['enabled' => false, 'measurementId' => ''],
				'clarity' => ['enabled' => false, 'projectId' => ''],
				'connectors' => new \stdClass(),
			],
		], JSON_THROW_ON_ERROR));

		$validated = $settings->validateApplicationSettings($input);
		self::assertSame(['source' => 'asset', 'assetId' => 15], $validated['seo']['defaultSocialImage']);

		// Overriding a structured DAM default with a plain URL must replace atomically
		$resolved = $settings->resolve($validated, ['seo' => ['defaultSocialImage' => '/media/site-override.png']]);
		self::assertSame('/media/site-override.png', $resolved['effective']['seo']['defaultSocialImage']);
	}

	public function testSeoMetadataResolverPrefersSocialSafeJpegOverAvifAndIncludesDimensionsAndAlt(): void
	{
		$site = new Site();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($site, 3);

		$token = str_repeat('9', 64);
		$asset = new UploadedDigitalAsset('Share Card', 'image/jpeg', $token);
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($asset, 88);
		$asset->setVisibility('public')->setWidth(2000)->setHeight(1050)->setDefaultAlt('Editorial cover photo');

		$avifVariant = new DigitalAssetVariant($asset, 'w1280_avif', 'k1', 'image/avif', 40000, 1280, 672, '11111111aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'avif', 65, false);
		$jpegPreview = new DigitalAssetVariant($asset, 'preview', 'k2', 'image/jpeg', 95000, 1200, 630, '22222222aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'jpg', 82, false);

		$assetRepo = $this->createMock(DigitalAssetRepository::class);
		$assetRepo->expects(self::once())
			->method('findAccessibleToSite')
			->with(88, $site)
			->willReturn($asset);

		$variantRepo = $this->createMock(DigitalAssetVariantRepository::class);
		$variantRepo->expects(self::once())
			->method('findByAsset')
			->with($asset)
			->willReturn([$avifVariant, $jpegPreview]);

		$settings = new ContentOptimizationSettings();
		$resolver = new SeoMetadataResolver($settings, new PageSeoSettingsNormalizer(), $assetRepo, $variantRepo);

		$metadata = $resolver->resolve(
			$settings->defaults(),
			[],
			['iikiti_content_optimization_og_image' => ['source' => 'asset', 'assetId' => 88]],
			'Launch Post',
			'https://example.test/blog/launch',
			'Example Site',
			$site,
		);

		self::assertSame('https://example.test/assets/'.$token.'/preview?v=22222222', $metadata['openGraph']['image']);
		self::assertSame(1200, $metadata['openGraph']['imageWidth']);
		self::assertSame(630, $metadata['openGraph']['imageHeight']);
		self::assertSame('Editorial cover photo', $metadata['openGraph']['imageAlt']);
		self::assertSame($metadata['openGraph']['image'], $metadata['twitter']['image']);
		self::assertSame(1200, $metadata['twitter']['imageWidth']);
		self::assertSame(630, $metadata['twitter']['imageHeight']);
		self::assertSame('Editorial cover photo', $metadata['twitter']['imageAlt']);
	}

	public function testSeoMetadataResolverNeverExposesPrivateDamAssets(): void
	{
		$site = new Site();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($site, 3);

		$privateAsset = new UploadedDigitalAsset('Confidential', 'image/jpeg', str_repeat('8', 64));
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($privateAsset, 99);
		$privateAsset->setVisibility('private');

		$assetRepo = $this->createStub(DigitalAssetRepository::class);
		$assetRepo->method('findAccessibleToSite')->willReturn($privateAsset);

		$settings = new ContentOptimizationSettings();
		$resolver = new SeoMetadataResolver($settings, new PageSeoSettingsNormalizer(), $assetRepo);

		$metadata = $resolver->resolve(
			$settings->defaults(),
			[],
			['iikiti_content_optimization_og_image' => ['source' => 'asset', 'assetId' => 99]],
			'Secret Page',
			'https://example.test/secret',
			'Example Site',
			$site,
		);

		self::assertSame('', $metadata['openGraph']['image']);
		self::assertNull($metadata['openGraph']['imageWidth']);
		self::assertNull($metadata['openGraph']['imageHeight']);
		self::assertSame('', $metadata['openGraph']['imageAlt']);
	}
}
