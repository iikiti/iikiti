<?php

declare(strict_types=1);

namespace iikiti\CMS\Plugin;

use iikiti\CMS\Value\LocaleTag;

/** Plugin-declared localization and query capabilities for an object property. */
final readonly class ObjectPropertyDefinition
{
	public string $objectType;
	public string $propertyName;
	/** @var list<string> */
	public array $locales;

	/**
	 * @param array<array-key,mixed> $locales
	 */
	public function __construct(
		string $objectType,
		string $propertyName,
		array $locales = [],
		public ?LocaleTag $defaultLocale = null,
		public bool $allowMixedLanguage = false,
		public bool $searchable = false,
		public bool $sortable = false,
		public bool $allowAnyLocale = false,
	) {
		if ('*' !== $objectType && 1 !== preg_match('/^[A-Za-z][A-Za-z0-9_\\\\]*$/', $objectType)) {
			throw new \InvalidArgumentException(sprintf('Invalid object type "%s" in property definition.', $objectType));
		}
		if (1 !== preg_match('/^[a-z][A-Za-z0-9_.-]*$/', $propertyName)) {
			throw new \InvalidArgumentException(sprintf('Invalid object property name "%s".', $propertyName));
		}
		if (!array_is_list($locales)) {
			throw new \InvalidArgumentException('Property locales must be an ordered list of BCP 47 tags.');
		}

		$normalizedLocales = [];
		foreach ($locales as $locale) {
			if (!$locale instanceof LocaleTag && !is_string($locale)) {
				throw new \InvalidArgumentException('Property locales must be BCP 47 locale strings.');
			}
			$tag = (string) ($locale instanceof LocaleTag ? $locale : LocaleTag::fromString($locale));
			if (isset($normalizedLocales[$tag])) {
				throw new \InvalidArgumentException(sprintf('Property definition contains duplicate locale "%s".', $tag));
			}
			$normalizedLocales[$tag] = true;
		}

		if (null !== $defaultLocale && !isset($normalizedLocales[$defaultLocale->getTag()]) && !$allowAnyLocale) {
			throw new \InvalidArgumentException('A property default locale must be included in its declared locales.');
		}
		if (($searchable || $sortable) && [] === $normalizedLocales && !$allowAnyLocale) {
			throw new \InvalidArgumentException('Searchable or sortable properties must declare at least one locale.');
		}

		$this->objectType = strtolower($objectType);
		$this->propertyName = $propertyName;
		$this->locales = array_keys($normalizedLocales);
	}

	public function key(): string
	{
		return $this->objectType.'::'.$this->propertyName;
	}

	public function supportsLocale(string $locale): bool
	{
		if ($this->allowAnyLocale) {
			return true;
		}

		$actual = LocaleTag::fromString($locale);
		foreach ($this->locales as $declaredLocale) {
			$declared = LocaleTag::fromString($declaredLocale);
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
