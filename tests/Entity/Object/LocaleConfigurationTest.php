<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity\Object;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use PHPUnit\Framework\TestCase;

final class LocaleConfigurationTest extends TestCase
{
	public function testApplicationStoresCanonicalDefaultAndSupportedLocales(): void
	{
		$application = new Application();
		$application->setLocaleSettings('en-ca', ['en-ca', 'fr-CA'], ['en', 'fr-ca']);

		self::assertSame('en-CA', $application->getDefaultLocale()?->getTag());
		self::assertSame(['en-CA', 'fr-CA'], array_map(static fn ($locale) => $locale->getTag(), $application->getSupportedUiLocales()));
		self::assertSame(['en', 'fr-CA'], array_map(static fn ($locale) => $locale->getTag(), $application->getSupportedContentLocales()));
	}

	public function testSiteCanLeaveSettingsUnsetToInheritFromItsApplication(): void
	{
		$site = new Site();

		self::assertNull($site->getDefaultLocale());
		self::assertNull($site->getSupportedUiLocales());
		self::assertNull($site->getSupportedContentLocales());

		$site->setLocaleSettings('fr-CA', ['fr-CA'], null);

		self::assertSame('fr-CA', $site->getDefaultLocale()?->getTag());
		self::assertSame(['fr-CA'], array_map(static fn ($locale) => $locale->getTag(), $site->getSupportedUiLocales()));
		self::assertNull($site->getSupportedContentLocales());
	}

	public function testSettingsRejectInvalidLocaleTags(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		(new Site())->setLocaleSettings('en_US');
	}

	public function testContentObjectStoresItsLocaleSettingsInObjectProperties(): void
	{
		$object = new DbObject();
		$object->setLocaleSettings('fr-CA', null, ['fr-CA', 'en-CA']);

		self::assertSame('fr-CA', $object->getDefaultLocale()?->getTag());
		self::assertSame(['fr-CA', 'en-CA'], array_map(static fn ($locale) => $locale->getTag(), $object->getSupportedContentLocales()));
	}
}
