<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Plugin\ObjectPropertyDefinitionRegistry;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;

/** Enforces plugin locale declarations before localized values are persisted. */
final readonly class ObjectPropertyValueValidator
{
	private LocaleResolver $locales;
	private ?ApplicationRegistry $applications;

	public function __construct(
		private ObjectPropertyDefinitionRegistry $definitions,
		?LocaleResolver $locales = null,
		?ApplicationRegistry $applications = null,
	) {
		$this->locales = $locales ?? new LocaleResolver();
		$this->applications = $applications;
	}

	public function validate(DbObject $object, ObjectProperty $property): void
	{
		$localized = $property->getLocalizedValue();
		if (null === $localized) {
			return;
		}

		$type = $object->getType() ?? strtolower((new \ReflectionClass($object))->getShortName());
		$name = $property->getName();
		$definition = null === $name ? null : (
			$this->definitions->get($type, $name) ??
			$this->definitions->get($object::class, $name)
		);
		if (null === $definition) {
			throw new \InvalidArgumentException(sprintf('Localized property "%s" is not declared by an active plugin for object type "%s".', $name ?? '', $type));
		}
		if ([] !== $localized->getSegments() && !$definition->allowMixedLanguage) {
			throw new \InvalidArgumentException(sprintf('Property "%s" does not allow mixed-language segments.', $name));
		}
		$scopeLocales = $object->getSupportedContentLocales();
		$site = $this->currentSite();
		if (null === $scopeLocales && $site instanceof Site) {
			$scopeLocales = $site->getSupportedContentLocales();
		}
		if (null === $scopeLocales && $site instanceof Site && $this->applications?->hasCurrentApplication()) {
			$application = $this->applications->getCurrent();
			$scopeLocales = $application->getSupportedContentLocales();
		}

		foreach ($localized->getLocales() as $locale) {
			if (!$definition->supportsLocale($locale)) {
				throw new \InvalidArgumentException(sprintf('Locale "%s" is not declared for property "%s".', $locale, $name));
			}
			if (null !== $scopeLocales && !$this->locales->isLocaleAllowed($locale, $scopeLocales)) {
				throw new \InvalidArgumentException(sprintf('Locale "%s" is not enabled for this object/site/application.', $locale));
			}
		}
	}

	private function currentSite(): ?Site
	{
		try {
			return SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null;
		} catch (\Error) {
			return null;
		}
	}
}
