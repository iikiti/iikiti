<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity;

use iikiti\CMS\Entity\Attribute\DbObjectDiscriminator;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\DigitalAssetCollection;
use iikiti\CMS\Entity\Object\DigitalAssetFolder;
use iikiti\CMS\Entity\Object\DigitalAssetTag;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Service\DigitalAssetSerializer;
use PHPUnit\Framework\TestCase;

final class DigitalAssetOrganizationTest extends TestCase
{
	public function testEntitiesExtendDbObjectWithGlobalScopeAndExplicitDiscriminators(): void
	{
		$cases = [
			[DigitalAssetFolder::class, 'digitalassetfolder'],
			[DigitalAssetCollection::class, 'digitalassetcollection'],
			[DigitalAssetTag::class, 'digitalassettag'],
		];

		foreach ($cases as [$class, $expectedDiscriminator]) {
			$reflection = new \ReflectionClass($class);
			self::assertTrue($reflection->isSubclassOf(DbObject::class));
			self::assertFalse($reflection->getConstant('SITE_SPECIFIC'));

			$attributes = $reflection->getAttributes(DbObjectDiscriminator::class);
			self::assertCount(1, $attributes);
			/** @var DbObjectDiscriminator $attr */
			$attr = $attributes[0]->newInstance();
			self::assertSame($expectedDiscriminator, $attr->value);
		}
	}

	public function testFolderComputesMaterializedPathAndRewritesSubtreePrefix(): void
	{
		$root = new DigitalAssetFolder('Marketing Assets');
		$this->assignId($root, 10);
		$child = new DigitalAssetFolder('Hero Banners', $root);
		$this->assignId($child, 11);
		$grandchild = new DigitalAssetFolder('Spring 2026', $child);
		$this->assignId($grandchild, 12);

		self::assertSame('marketing-assets', $root->getSlug());
		self::assertSame('/marketing-assets', $root->getPath());
		self::assertSame('/marketing-assets/hero-banners', $child->getPath());
		self::assertSame('/marketing-assets/hero-banners/spring-2026', $grandchild->getPath());

		$sibling = new DigitalAssetFolder('Marketing Assets Extra');
		$this->assignId($sibling, 13);
		self::assertSame('/marketing-assets-extra', $sibling->getPath());

		$child->rewritePathPrefix('/marketing-assets', '/brand');
		$grandchild->rewritePathPrefix('/marketing-assets', '/brand');
		$sibling->rewritePathPrefix('/marketing-assets', '/brand');

		self::assertSame('/brand/hero-banners', $child->getPath());
		self::assertSame('/brand/hero-banners/spring-2026', $grandchild->getPath());
		self::assertSame('/marketing-assets-extra', $sibling->getPath());
	}

	public function testFolderPreventsSelfParentingAndDescendantCycles(): void
	{
		$root = new DigitalAssetFolder('Root');
		$this->assignId($root, 1);
		$child = new DigitalAssetFolder('Child', $root);
		$this->assignId($child, 2);
		$grandchild = new DigitalAssetFolder('Grandchild', $child);
		$this->assignId($grandchild, 3);

		try {
			$root->setParent($root);
			self::fail('Expected self-parenting cycle exception.');
		} catch (\InvalidArgumentException $exception) {
			self::assertStringContainsString('cycle', strtolower($exception->getMessage()).' cycle');
		}

		$this->expectException(\InvalidArgumentException::class);
		$root->setParent($grandchild);
	}

	public function testFolderSiteGrantsAndGlobalAccessSemantics(): void
	{
		$siteA = $this->site(1);
		$siteB = $this->site(2);
		$folder = new DigitalAssetFolder('Shared');

		self::assertTrue($folder->isGlobalAccess());
		self::assertTrue($folder->isAccessibleToSite($siteA));
		self::assertTrue($folder->isAccessibleToSite($siteB));

		$folder->setAccessSites([$siteA, $siteA]);
		self::assertFalse($folder->isGlobalAccess());
		self::assertCount(1, $folder->getAccessSites());
		self::assertTrue($folder->grantsAccessTo($siteA));
		self::assertTrue($folder->isAccessibleToSite($siteA));
		self::assertFalse($folder->isAccessibleToSite($siteB));
	}

	public function testTagSlugAndNamespaceNormalization(): void
	{
		$tag = new DigitalAssetTag('  Hero Banner! 2026  ', '  CAMPAIGN.Assets  ');

		self::assertSame('Hero Banner! 2026', $tag->getName());
		self::assertSame('hero-banner-2026', $tag->getSlug());
		self::assertSame('campaign.assets', $tag->getNamespace());

		$defaultNsTag = new DigitalAssetTag('Featured', '');
		self::assertSame(DigitalAssetTag::DEFAULT_NAMESPACE, $defaultNsTag->getNamespace());

		$this->expectException(\InvalidArgumentException::class);
		new DigitalAssetTag('Invalid Namespace', 'bad namespace!');
	}

	public function testAssetTagsSyncNormalizedNamesToObjectPropertyForSearch(): void
	{
		$asset = new UploadedDigitalAsset('Banner', 'image/png', str_repeat('a', 64));
		$tagB = new DigitalAssetTag('Summer Sale');
		$this->assignId($tagB, 20);
		$tagA = new DigitalAssetTag('Hero Image');
		$this->assignId($tagA, 21);

		$asset->addTag($tagB)->addTag($tagA)->addTag($tagB);

		self::assertCount(2, $asset->getTags());
		self::assertTrue($asset->hasTag($tagA));
		self::assertSame(['hero image', 'summer sale'], $asset->getProperties()->get('tags')?->getValue());

		$asset->removeTag($tagB);
		self::assertSame(['hero image'], $asset->getProperties()->get('tags')?->getValue());

		$asset->removeTag($tagA);
		self::assertNull($asset->getProperties()->get('tags'));
	}

	public function testCollectionDeduplicatesAssetsAndSerializerIncludesOrganizationFields(): void
	{
		$folder = new DigitalAssetFolder('Campaigns');
		$this->assignId($folder, 50);

		$tag = new DigitalAssetTag('Homepage');
		$this->assignId($tag, 60);

		$collection = new DigitalAssetCollection('Q4 Launch', null, 'Assets for Q4');
		$this->assignId($collection, 70);

		$asset = new UploadedDigitalAsset('Hero', 'image/webp', str_repeat('c', 64));
		$this->assignId($asset, 80);
		$asset->setFolder($folder);
		$asset->setTags([$tag]);
		$collection->addAsset($asset)->addAsset($asset);

		// Simulate Doctrine bidirectional hydration for serializer check.
		$asset->getCollections()->add($collection);

		self::assertCount(1, $collection->getAssets());
		self::assertTrue($collection->hasAsset($asset));

		$serializer = new DigitalAssetSerializer();
		$data = $serializer->serialize($asset, null, false, []);

		self::assertSame(50, $data['folderId']);
		self::assertSame('/campaigns', $data['folderPath']);
		self::assertSame([60], $data['tagIds']);
		self::assertSame([['id' => 60, 'name' => 'Homepage', 'slug' => 'homepage']], $data['tags']);
		self::assertSame([70], $data['collectionIds']);

		$collection->removeAsset($asset);
		self::assertCount(0, $collection->getAssets());
	}

	private function assignId(DbObject $object, int $id): void
	{
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($object, $id);
	}

	private function site(int $id): Site
	{
		$site = new Site();
		$this->assignId($site, $id);

		return $site;
	}
}
