<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Service\LocaleCollationManager;
use iikiti\CMS\Service\PostgreSQLTextSearchConfigResolver;
use iikiti\CMS\Value\LocaleTag;
use PHPUnit\Framework\TestCase;

final class LocaleSearchIndexTest extends TestCase
{
	public function testLocaleTagsGenerateStableSafeIndexIdentifiers(): void
	{
		$locale = LocaleTag::fromString('fr-CA');
		[$collation, $index] = LocaleCollationManager::names($locale);

		self::assertSame(['iikiti_locale_'.substr(hash('sha256', 'fr-CA'), 0, 16), 'idx_localized_property_sort_'.substr(hash('sha256', 'fr-CA'), 0, 16)], [$collation, $index]);
		self::assertSame(['iikiti_locale_und', 'idx_localized_property_sort_und'], LocaleCollationManager::names(LocaleTag::fromString('und')));
	}

	public function testTextSearchConfigUsesLanguageSubtagAndSafeSimpleFallback(): void
	{
		$resolver = new PostgreSQLTextSearchConfigResolver();

		self::assertSame('english', $resolver->resolve(LocaleTag::fromString('en-CA')));
		self::assertSame('french', $resolver->resolve(LocaleTag::fromString('fr')));
		self::assertSame('simple', $resolver->resolve(LocaleTag::fromString('und')));
		self::assertSame('simple', $resolver->resolve(LocaleTag::fromString('zh-Hant-TW')));
	}
}
