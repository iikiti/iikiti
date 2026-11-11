<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Value\LocaleTag;

/** Resolves UI, content, and presentation locales without conflating them. */
final class LocaleResolver
{
	/**
	 * @param list<string|LocaleTag> $availableLocales
	 */
	public function resolveContentLocale(
		array $availableLocales,
		?LocaleTag $userPreference,
		?LocaleTag $objectLocale,
		?LocaleTag $siteLocale,
		?LocaleTag $applicationLocale,
	): LocaleTag {
		$available = [];
		foreach ($availableLocales as $locale) {
			$tag = $locale instanceof LocaleTag ? $locale : LocaleTag::fromString($locale);
			$available[$tag->getTag()] = $tag;
		}

		if ([] === $available) {
			return $objectLocale ?? $siteLocale ?? $applicationLocale ?? LocaleTag::fromString('en');
		}

		foreach ([$userPreference, $objectLocale, $siteLocale, $applicationLocale] as $preferred) {
			if (null !== $match = $this->matchAvailableLocale($preferred, $available)) {
				return $match;
			}
		}

		ksort($available, SORT_STRING);

		return $available[array_key_first($available)];
	}

	public function resolveUiLocale(
		?LocaleTag $userPreference,
		?LocaleTag $siteLocale,
		?LocaleTag $applicationLocale,
	): LocaleTag {
		return $userPreference ?? $siteLocale ?? $applicationLocale ?? LocaleTag::fromString('en');
	}

	public function resolvePresentationLocale(
		?LocaleTag $userPreference,
		?LocaleTag $contentLocale,
		?LocaleTag $siteLocale,
		?LocaleTag $applicationLocale,
	): LocaleTag {
		return $userPreference ?? $contentLocale ?? $siteLocale ?? $applicationLocale ?? LocaleTag::fromString('en');
	}

	/**
	 * @param array<string,LocaleTag> $availableLocales
	 */
	public function matchAvailableLocale(?LocaleTag $preferred, array $availableLocales): ?LocaleTag
	{
		if (null === $preferred) {
			return null;
		}

		foreach ($preferred->getLookupChain() as $candidate) {
			if (isset($availableLocales[$candidate])) {
				return $availableLocales[$candidate];
			}
		}

		$matches = [];
		$preferredScript = $preferred->getScript();
		foreach ($availableLocales as $tag => $available) {
			if ($available->getLanguage() !== $preferred->getLanguage()) {
				continue;
			}
			if (null !== $preferredScript && $preferredScript !== $available->getScript()) {
				continue;
			}
			$matches[$tag] = $available;
		}

		if ([] === $matches) {
			return null;
		}

		ksort($matches, SORT_STRING);

		return $matches[array_key_first($matches)];
	}

	/** An explicit base-language declaration covers its regional variants. */
	/** @param array<array-key,string|LocaleTag> $allowedLocales */
	public function isLocaleAllowed(string|LocaleTag $locale, array $allowedLocales): bool
	{
		$actual = $locale instanceof LocaleTag ? $locale : LocaleTag::fromString($locale);
		foreach ($allowedLocales as $allowed) {
			$declared = $allowed instanceof LocaleTag ? $allowed : LocaleTag::fromString($allowed);
			if ($actual->getTag() === $declared->getTag() ||
				in_array($declared->getTag(), $actual->getLookupChain(), true) ||
				in_array($actual->getTag(), $declared->getLookupChain(), true) ||
				$declared->getTag() === $declared->getLanguage() && $actual->getLanguage() === $declared->getLanguage()
			) {
				return true;
			}
		}

		return false;
	}
}
