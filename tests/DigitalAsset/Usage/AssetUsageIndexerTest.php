<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\DigitalAsset\Usage;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\DigitalAsset\Usage\AssetUsageIndexer;
use iikiti\CMS\DigitalAsset\Usage\BlockTreeAssetUsageExtractor;
use iikiti\CMS\DigitalAsset\Usage\SettingsAssetUsageExtractor;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\DigitalAssetUsage;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetUsageRepository;
use PHPUnit\Framework\TestCase;

final class AssetUsageIndexerTest extends TestCase
{
	public function testBlockTreeExtractorFindsStructuredAssetsNestedChildrenAndRichtextUrls(): void
	{
		$token = str_repeat('a', 64);
		$signedToken = str_repeat('b', 64);

		$tree = [
			'main' => [
				[
					'type' => 'image',
					'content' => [
						'media' => [
							'source' => 'asset',
							'assetId' => 42,
						],
					],
				],
				[
					'type' => 'container',
					'content' => [],
					'children' => [
						[
							'type' => 'richtext',
							'content' => [
								'html' => sprintf('<p><img src="/assets/%s/preview?v=12345678"><a href="/assets/signed/%s/original">PDF</a></p>', $token, $signedToken),
							],
						],
						[
							'type' => 'gallery',
							'content' => [
								'items' => [
									['source' => 'asset', 'assetId' => '99'],
									['source' => 'url', 'url' => 'https://example.com/external.jpg'],
								],
							],
						],
					],
				],
			],
		];

		$extractor = new BlockTreeAssetUsageExtractor();
		$matches = $extractor->extractFromValue($tree, 'blocks');

		self::assertCount(4, $matches);
		self::assertSame(['assetId' => 42, 'fieldPath' => 'blocks.main[0].media'], $matches[0]);
		self::assertSame(['publicToken' => $token, 'fieldPath' => 'blocks.main[1].children[0].html'], $matches[1]);
		self::assertSame(['publicToken' => $signedToken, 'fieldPath' => 'blocks.main[1].children[0].html'], $matches[2]);
		self::assertSame(['assetId' => 99, 'fieldPath' => 'blocks.main[1].children[1].items[0]'], $matches[3]);
	}

	public function testSettingsExtractorFindsSocialImagesAndSiteConfiguration(): void
	{
		$ogToken = str_repeat('c', 64);
		$settings = [
			'og_image' => ['source' => 'asset', 'assetId' => 17],
			'twitter_image' => '/assets/'.$ogToken.'/original',
			'seo' => [
				'defaultSocialImage' => ['assetId' => 88],
			],
		];

		$extractor = new SettingsAssetUsageExtractor();
		$matches = $extractor->extractFromValue($settings, 'settings');

		self::assertCount(3, $matches);
		self::assertSame(['assetId' => 17, 'fieldPath' => 'settings.og_image'], $matches[0]);
		self::assertSame(['publicToken' => $ogToken, 'fieldPath' => 'settings.twitter_image'], $matches[1]);
		self::assertSame(['assetId' => 88, 'fieldPath' => 'settings.seo.defaultSocialImage'], $matches[2]);
	}

	public function testIndexerResolvesPublicTokensDeduplicatesAndEnrichesFindUsage(): void
	{
		$token = str_repeat('d', 64);
		$asset = new UploadedDigitalAsset('Hero', 'image/png', $token);
		$this->assignId($asset, 500);

		$template = new Template();
		$this->assignId($template, 12);
		$template->setProperties(new ArrayCollection());
		$template->setSiteId(3);
		$template->setProperty('title', 'Homepage Layout');
		$template->setProperty('blocks', [
			'hero' => [
				[
					'type' => 'hero',
					'content' => [
						'background' => ['source' => 'asset', 'assetId' => 500],
						'body' => sprintf('<img src="/assets/%s/thumbnail">', $token),
					],
				],
			],
		]);
		$template->setProperty('blocks_draft', [
			'hero' => [
				[
					'type' => 'hero',
					'content' => [
						'background' => ['source' => 'asset', 'assetId' => 500],
					],
				],
			],
		]);

		$site = new Site();
		$this->assignId($site, 3);
		$site->setProperties(new ArrayCollection());
		$site->setProperty('name', 'Main Site');

		$em = $this->createStub(EntityManagerInterface::class);
		$em->method('find')->willReturnCallback(static fn (string $class, mixed $id): ?object => match (true) {
			Template::class === $class && 12 === (int) $id => $template,
			Site::class === $class && 3 === (int) $id => $site,
			default => null,
		});

		$assetRepo = $this->createStub(DigitalAssetRepository::class);
		$assetRepo->method('findByPublicToken')->willReturnCallback(
			static fn (string $t): ?UploadedDigitalAsset => $t === $token ? $asset : null,
		);

		$capturedRows = [];
		$usageRepo = $this->createMock(DigitalAssetUsageRepository::class);
		$usageRepo->expects(self::once())->
			method('replaceContextUsages')->
			with('template', 12, self::callback(static function (array $rows) use (&$capturedRows): bool {
				$capturedRows = $rows;

				return 3 === count($rows);
			}));

		$usageRepo->method('findByAssetId')->with(500)->willReturn([
			new DigitalAssetUsage(500, 'template', 12, 'blocks.hero[0].background', false, 3),
			new DigitalAssetUsage(500, 'template', 12, 'blocks_draft.hero[0].background', true, 3),
		]);

		$indexer = new AssetUsageIndexer($em, $usageRepo, $assetRepo);
		$indexer->reindexContext('template', 12);

		self::assertSame(500, $capturedRows[0]['assetId']);
		self::assertFalse($capturedRows[0]['isDraft']);
		self::assertSame('blocks.hero[0].background', $capturedRows[0]['fieldPath']);
		self::assertSame(500, $capturedRows[1]['assetId']);
		self::assertSame('blocks.hero[0].body', $capturedRows[1]['fieldPath']);
		self::assertTrue($capturedRows[2]['isDraft']);

		$refs = $indexer->findUsage(500);
		self::assertCount(2, $refs);
		self::assertSame('Homepage Layout', $refs[0]->contextTitle);
		self::assertSame('/admin#/templates/12', $refs[0]->editUrl);
		self::assertSame('Main Site', $refs[0]->siteName);
		self::assertFalse($refs[0]->isDraft);
		self::assertTrue($refs[1]->isDraft);

		$array = $refs[0]->toArray();
		self::assertSame('template', $array['contextType']);
		self::assertSame('template', $array['entityType']);
		self::assertSame(12, $array['entityId']);
		self::assertSame('Homepage Layout', $array['title']);
		self::assertSame('/admin#/templates/12', $array['adminUrl']);
	}

	public function testContextTypeResolutionForAllEntityTypes(): void
	{
		self::assertSame('page', AssetUsageIndexer::contextTypeForObject(new Page()));
		self::assertSame('template', AssetUsageIndexer::contextTypeForObject(new Template()));
		self::assertSame('site', AssetUsageIndexer::contextTypeForObject(new Site()));
	}

	private function assignId(DbObject $object, int $id): void
	{
		$ref = new \ReflectionProperty(DbObject::class, 'id');
		$ref->setValue($object, $id);
	}
}
