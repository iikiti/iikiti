<?php

declare(strict_types=1);

namespace iikiti\CMS\Trait;

use iikiti\CMS\Value\LocaleTag;

/** Language defaults and availability persisted in an object's configuration. */
trait LocaleConfigurableTrait
{
	public const LOCALE_CONFIGURATION_KEY = 'locales';
	public const LOCALE_SETTINGS_PROPERTY = 'locale_settings';

	public function getDefaultLocale(): ?LocaleTag
	{
		$locale = $this->getLocaleConfiguration()['default'] ?? null;

		return is_string($locale) ? LocaleTag::tryFromString($locale) : null;
	}

	/** @return list<LocaleTag>|null Null means inherit from the parent scope. */
	public function getSupportedUiLocales(): ?array
	{
		return $this->readLocaleList('ui');
	}

	/** @return list<LocaleTag>|null Null means inherit from the parent scope. */
	public function getSupportedContentLocales(): ?array
	{
		return $this->readLocaleList('content');
	}

	/**
	 * @param list<LocaleTag|string>|null $uiLocales
	 * @param list<LocaleTag|string>|null $contentLocales
	 */
	public function setLocaleSettings(
		LocaleTag|string|null $defaultLocale,
		?array $uiLocales = null,
		?array $contentLocales = null,
	): void {
		$settings = $this->getLocaleConfiguration();
		$settings['default'] = null === $defaultLocale ? null : (string) self::localeTag($defaultLocale);
		$settings['ui'] = null === $uiLocales ? null : self::normalizeLocaleList($uiLocales);
		$settings['content'] = null === $contentLocales ? null : self::normalizeLocaleList($contentLocales);

		if (method_exists($this, 'getConfiguration') && method_exists($this, 'setConfiguration')) {
			$configuration = $this->getConfiguration();
			$configuration->set(self::LOCALE_CONFIGURATION_KEY, $settings);
			$this->setConfiguration($configuration);
		} else {
			$this->setProperty(self::LOCALE_SETTINGS_PROPERTY, $settings);
		}
	}

	/**
	 * @return list<LocaleTag>|null
	 */
	private function readLocaleList(string $key): ?array
	{
		$locales = $this->getLocaleConfiguration()[$key] ?? null;
		if (null === $locales) {
			return null;
		}
		if (!is_array($locales)) {
			throw new \UnexpectedValueException(sprintf('Configured %s locales must be a list.', $key));
		}

		return array_map(static fn (mixed $locale): LocaleTag => self::localeTag($locale), array_values($locales));
	}

	/** @return array<string,mixed> */
	private function getLocaleConfiguration(): array
	{
		if (method_exists($this, 'getConfiguration')) {
			$configuration = $this->getConfiguration()->get(self::LOCALE_CONFIGURATION_KEY);
		} else {
			$configuration = $this->getProperties()->get(self::LOCALE_SETTINGS_PROPERTY)?->getValue();
		}

		return is_array($configuration) ? $configuration : [];
	}

	/** @param list<LocaleTag|string> $locales
	 * @return list<string>
	 */
	private static function normalizeLocaleList(array $locales): array
	{
		$normalized = [];
		foreach ($locales as $locale) {
			$normalized[(string) self::localeTag($locale)] = true;
		}

		return array_keys($normalized);
	}

	private static function localeTag(mixed $locale): LocaleTag
	{
		if ($locale instanceof LocaleTag) {
			return $locale;
		}
		if (is_string($locale)) {
			return LocaleTag::fromString($locale);
		}

		throw new \InvalidArgumentException('Locale settings must contain BCP 47 locale strings.');
	}
}
