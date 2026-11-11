<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Value;

use iikiti\CMS\Value\LocaleTag;
use PHPUnit\Framework\TestCase;

final class LocaleTagTest extends TestCase
{
	public function testCanonicalizesLanguageScriptAndRegion(): void
	{
		$locale = LocaleTag::fromString('zh-hant-tw');

		self::assertSame('zh-Hant-TW', $locale->getTag());
		self::assertSame('zh_Hant_TW', $locale->toIcuLocale());
	}

	public function testBuildsRegionalFallbackChainWithoutLosingStoredRegion(): void
	{
		$locale = LocaleTag::fromString('fr-CA');

		self::assertSame('fr-CA', $locale->getTag());
		self::assertSame(['fr-CA', 'fr'], $locale->getLookupChain());
	}

	public function testUndIsPreservedAsTheRootLanguageTag(): void
	{
		$locale = LocaleTag::fromString('und');

		self::assertSame('und', $locale->getTag());
		self::assertSame('und', $locale->toIcuLocale());
	}

	public function testRejectsNonBcp47AndMalformedTags(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		LocaleTag::fromString('en_US');
	}

	public function testRejectsDuplicateExtensionSingletons(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		LocaleTag::fromString('en-u-ca-gregory-u-nu-latn');
	}
}
