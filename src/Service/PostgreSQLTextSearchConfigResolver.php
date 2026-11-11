<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Value\LocaleTag;

/** Maps BCP 47 language subtags to PostgreSQL's installed text search configs. */
final class PostgreSQLTextSearchConfigResolver
{
	private const array CONFIGS = [
		'ar' => 'arabic',
		'da' => 'danish',
		'de' => 'german',
		'en' => 'english',
		'es' => 'spanish',
		'fi' => 'finnish',
		'fr' => 'french',
		'hu' => 'hungarian',
		'it' => 'italian',
		'nl' => 'dutch',
		'no' => 'norwegian',
		'pt' => 'portuguese',
		'ro' => 'romanian',
		'ru' => 'russian',
		'sv' => 'swedish',
		'tr' => 'turkish',
	];

	public function resolve(LocaleTag $locale): string
	{
		return self::CONFIGS[$locale->getLanguage()] ?? 'simple';
	}
}
