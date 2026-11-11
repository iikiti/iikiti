<?php

declare(strict_types=1);

namespace iikiti\CMS\Value;

use iikiti\CMS\Service\LocaleResolver;

/** A property value with translations or ordered language-tagged text segments. */
final readonly class LocalizedValue implements \JsonSerializable
{
	public const string TYPE = 'localized';
	public const int VERSION = 1;

	/**
	 * @param array<string,string>                   $translations
	 * @param list<array{text:string,locale:string}> $segments
	 */
	private function __construct(
		private array $translations = [],
		private array $segments = [],
	) {
	}

	/** @param array<string,string> $translations */
	public static function fromTranslations(array $translations): self
	{
		return new self(self::normalizeTranslations($translations));
	}

	/** @param list<array{text:string,locale:string}> $segments */
	public static function fromSegments(array $segments): self
	{
		return new self([], self::normalizeSegments($segments));
	}

	/** @param array<string,mixed> $value */
	public static function fromArray(array $value): self
	{
		if (self::TYPE !== ($value['$type'] ?? null) || self::VERSION !== ($value['version'] ?? null)) {
			throw new \InvalidArgumentException('Unsupported localized property value format.');
		}

		$hasTranslations = array_key_exists('translations', $value);
		$hasSegments = array_key_exists('segments', $value);
		if ($hasTranslations === $hasSegments || 3 !== count($value)) {
			throw new \InvalidArgumentException('A localized value must contain exactly one of translations or segments.');
		}

		if ($hasTranslations) {
			if (!is_array($value['translations'])) {
				throw new \InvalidArgumentException('Localized translations must be a locale-to-string map.');
			}

			return self::fromTranslations($value['translations']);
		}

		if (!is_array($value['segments']) || !array_is_list($value['segments'])) {
			throw new \InvalidArgumentException('Localized segments must be an ordered list.');
		}

		return self::fromSegments($value['segments']);
	}

	public static function isLocalizedArray(mixed $value): bool
	{
		return is_array($value) && self::TYPE === ($value['$type'] ?? null);
	}

	/** @return array<string,string> */
	public function getTranslations(): array
	{
		return $this->translations;
	}

	/** @return list<array{text:string,locale:string}> */
	public function getSegments(): array
	{
		return $this->segments;
	}

	/** @return list<string> */
	public function getLocales(): array
	{
		$locales = [];
		foreach (array_keys($this->translations) as $locale) {
			$locales[$locale] = true;
		}
		foreach ($this->segments as $segment) {
			$locales[$segment['locale']] = true;
		}

		return array_keys($locales);
	}

	/**
	 * Returns the selected translation or the preserved inline segment list.
	 * Segment order is content and must not be changed by language selection.
	 *
	 * @return string|list<array{text:string,locale:string}>|null
	 */
	public function resolve(
		LocaleResolver $resolver,
		?LocaleTag $userPreference,
		?LocaleTag $objectLocale,
		?LocaleTag $siteLocale,
		?LocaleTag $applicationLocale,
	): string|array|null {
		if ([] !== $this->segments) {
			return $this->segments;
		}

		$locale = $resolver->resolveContentLocale(
			array_keys($this->translations),
			$userPreference,
			$objectLocale,
			$siteLocale,
			$applicationLocale,
		);

		return $this->translations[$locale->getTag()] ?? null;
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array
	{
		if ([] !== $this->segments) {
			return [
				'$type' => self::TYPE,
				'version' => self::VERSION,
				'segments' => $this->segments,
			];
		}

		return [
			'$type' => self::TYPE,
			'version' => self::VERSION,
			'translations' => $this->translations,
		];
	}

	/** @param array<mixed> $translations
	 * @return array<string,string>
	 */
	private static function normalizeTranslations(array $translations): array
	{
		if ([] === $translations) {
			throw new \InvalidArgumentException('A localized translation map cannot be empty.');
		}

		$normalized = [];
		foreach ($translations as $locale => $value) {
			if (!is_string($locale) || !is_string($value)) {
				throw new \InvalidArgumentException('Localized translations must map BCP 47 locale strings to text.');
			}
			$tag = (string) LocaleTag::fromString($locale);
			if (array_key_exists($tag, $normalized)) {
				throw new \InvalidArgumentException(sprintf('Localized value contains duplicate locale "%s".', $tag));
			}
			$normalized[$tag] = $value;
		}

		return $normalized;
	}

	/** @param array<mixed> $segments
	 * @return list<array{text:string,locale:string}>
	 */
	private static function normalizeSegments(array $segments): array
	{
		if ([] === $segments || !array_is_list($segments)) {
			throw new \InvalidArgumentException('A localized segment list must be a non-empty ordered list.');
		}

		$normalized = [];
		foreach ($segments as $segment) {
			if (!is_array($segment) || 2 !== count($segment) || !is_string($segment['text'] ?? null) || !is_string($segment['locale'] ?? null)) {
				throw new \InvalidArgumentException('Each localized segment must contain only text and locale strings.');
			}
			$normalized[] = [
				'text' => $segment['text'],
				'locale' => (string) LocaleTag::fromString($segment['locale']),
			];
		}

		return $normalized;
	}
}
