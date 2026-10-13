<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use PHPUnit\Framework\TestCase;

final class DigitalAssetTest extends TestCase
{
	public function testUploadedAssetDefaultsToPrivateAndSupportsSiteGrants(): void
	{
		$asset = new UploadedDigitalAsset('Photo', 'image/jpeg', str_repeat('a', 64));
		$firstSite = $this->site(11);
		$secondSite = $this->site(12);

		$asset->setAccessSites([$firstSite, $secondSite]);

		self::assertSame('private', $asset->getVisibility());
		self::assertSame('pending', $asset->getProcessingStatus());
		self::assertTrue($asset->grantsAccessTo($firstSite));
		self::assertTrue($asset->grantsAccessTo($secondSite));
		self::assertFalse($asset->grantsAccessTo($this->site(13)));
	}

	public function testVisibilityAcceptsOnlyPublicOrPrivate(): void
	{
		$asset = new UploadedDigitalAsset('Document', 'application/pdf', str_repeat('b', 64));
		$asset->setVisibility('public');

		self::assertSame('public', $asset->getVisibility());
		$this->expectException(\InvalidArgumentException::class);
		$asset->setVisibility('unrestricted');
	}

	private function site(int $id): Site
	{
		$site = new Site();
		$identifier = new \ReflectionProperty(DbObject::class, 'id');
		$identifier->setValue($site, $id);

		return $site;
	}
}
