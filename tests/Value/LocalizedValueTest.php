<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Value;

use iikiti\CMS\Service\LocaleResolver;
use iikiti\CMS\Value\LocaleTag;
use iikiti\CMS\Value\LocalizedValue;
use PHPUnit\Framework\TestCase;

final class LocalizedValueTest extends TestCase
{
	public function testStoresTranslationsByCanonicalLocale(): void
	{
		$value = LocalizedValue::fromTranslations(['en-ca' => 'Color', 'fr-CA' => 'Couleur']);

		self::assertSame([
			'$type' => 'localized',
			'version' => 1,
			'translations' => ['en-CA' => 'Color', 'fr-CA' => 'Couleur'],
		], $value->jsonSerialize());
	}

	public function testResolvesUserTranslationBeforeObjectSiteAndApplicationDefaults(): void
	{
		$value = LocalizedValue::fromTranslations([
			'en-GB' => 'Colour',
			'fr-CA' => 'Couleur',
		]);

		self::assertSame('Couleur', $value->resolve(
			new LocaleResolver(),
			LocaleTag::fromString('fr-CA'),
			LocaleTag::fromString('en-GB'),
			LocaleTag::fromString('de'),
			LocaleTag::fromString('en'),
		));
	}

	public function testRegionalUserLocaleFallsBackToTheBaseLanguageValue(): void
	{
		$value = LocalizedValue::fromTranslations(['fr' => 'Bonjour', 'en' => 'Hello']);

		self::assertSame('Bonjour', $value->resolve(
			new LocaleResolver(),
			LocaleTag::fromString('fr-CA'),
			null,
			null,
			null,
		));
	}

	public function testMixedLanguageSegmentsPreserveTextLanguageAndOrder(): void
	{
		$segments = [
			['text' => 'Bonjour, ', 'locale' => 'fr-CA'],
			['text' => 'world!', 'locale' => 'en-CA'],
		];
		$value = LocalizedValue::fromSegments($segments);

		self::assertSame($segments, $value->resolve(
			new LocaleResolver(),
			LocaleTag::fromString('en-CA'),
			null,
			null,
			null,
		));
		self::assertSame(['fr-CA', 'en-CA'], $value->getLocales());
	}

	public function testRejectsMalformedTranslationAndSegmentValues(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		LocalizedValue::fromArray([
			'$type' => 'localized',
			'version' => 1,
			'segments' => [['text' => 'Hello', 'locale' => 'en_US']],
		]);
	}

	public function testRejectsAmbiguousValueContainingBothRepresentations(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		LocalizedValue::fromArray([
			'$type' => 'localized',
			'version' => 1,
			'translations' => ['en' => 'Hello'],
			'segments' => [['text' => 'Hello', 'locale' => 'en']],
		]);
	}
}
