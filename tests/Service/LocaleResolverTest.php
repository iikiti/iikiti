<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Service\LocaleResolver;
use iikiti\CMS\Value\LocaleTag;
use PHPUnit\Framework\TestCase;

final class LocaleResolverTest extends TestCase
{
	private LocaleResolver $resolver;

	protected function setUp(): void
	{
		$this->resolver = new LocaleResolver();
	}

	public function testUserPreferenceWinsWhenARegionalTranslationExists(): void
	{
		$locale = $this->resolver->resolveContentLocale(
			['en-GB', 'en-CA'],
			LocaleTag::fromString('en-CA'),
			LocaleTag::fromString('en-GB'),
			LocaleTag::fromString('fr'),
			LocaleTag::fromString('en'),
		);

		self::assertSame('en-CA', $locale->getTag());
	}

	public function testRegionalPreferenceFallsBackToBaseTranslation(): void
	{
		$locale = $this->resolver->resolveContentLocale(
			['en', 'fr'],
			LocaleTag::fromString('en-CA'),
			LocaleTag::fromString('fr'),
			LocaleTag::fromString('de'),
			LocaleTag::fromString('en'),
		);

		self::assertSame('en', $locale->getTag());
	}

	public function testFallsBackThroughObjectSiteAndApplicationLocales(): void
	{
		$locale = $this->resolver->resolveContentLocale(
			['en', 'fr'],
			LocaleTag::fromString('es'),
			LocaleTag::fromString('fr-CA'),
			LocaleTag::fromString('de'),
			LocaleTag::fromString('en'),
		);

		self::assertSame('fr', $locale->getTag());
	}

	public function testLastAvailableTranslationFallbackIsDeterministic(): void
	{
		$locale = $this->resolver->resolveContentLocale(
			['sv', 'de', 'fr'],
			null,
			null,
			null,
			null,
		);

		self::assertSame('de', $locale->getTag());
	}

	public function testLegacyContentUsesConfiguredDefaultWhenNoTranslationsExist(): void
	{
		$locale = $this->resolver->resolveContentLocale(
			[],
			LocaleTag::fromString('es'),
			LocaleTag::fromString('fr-CA'),
			LocaleTag::fromString('de'),
			LocaleTag::fromString('en'),
		);

		self::assertSame('fr-CA', $locale->getTag());
	}

	public function testPresentationFormattingUsesFullUserLocale(): void
	{
		$locale = $this->resolver->resolvePresentationLocale(
			LocaleTag::fromString('en-CA'),
			LocaleTag::fromString('en-GB'),
			LocaleTag::fromString('fr-CA'),
			LocaleTag::fromString('en'),
		);

		self::assertSame('en-CA', $locale->getTag());
	}

	public function testPresentationFormattingFallsBackFromContentToSiteAndApplication(): void
	{
		self::assertSame('fr-CA', $this->resolver->resolvePresentationLocale(
			null,
			LocaleTag::fromString('fr-CA'),
			LocaleTag::fromString('de'),
			LocaleTag::fromString('en'),
		)->getTag());

		self::assertSame('de', $this->resolver->resolvePresentationLocale(
			null,
			null,
			LocaleTag::fromString('de'),
			LocaleTag::fromString('en'),
		)->getTag());
	}

	public function testUiLocaleUsesUserSiteApplicationAndEnglishFallback(): void
	{
		self::assertSame('fr-CA', $this->resolver->resolveUiLocale(
			LocaleTag::fromString('fr-CA'),
			LocaleTag::fromString('de'),
			LocaleTag::fromString('en'),
		)->getTag());

		self::assertSame('de', $this->resolver->resolveUiLocale(
			null,
			LocaleTag::fromString('de'),
			LocaleTag::fromString('en'),
		)->getTag());

		self::assertSame('en', $this->resolver->resolveUiLocale(null, null, null)->getTag());
	}

	public function testBaseLocaleAllowsRegionalVariantsButRegionDoesNotAllowItsSibling(): void
	{
		self::assertTrue($this->resolver->isLocaleAllowed('en-CA', ['en']));
		self::assertTrue($this->resolver->isLocaleAllowed('en', ['en-CA']));
		self::assertFalse($this->resolver->isLocaleAllowed('en-GB', ['en-CA']));
	}
}
