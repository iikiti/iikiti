<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Plugin\ObjectPropertyDefinitionRegistry;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Value\LocaleTag;
use iikiti\CMS\Value\ResolvedPropertyValue;
use Symfony\Bundle\SecurityBundle\Security;

/** Selects a property's translation using the active user/site/application context. */
final readonly class LocalizedPropertyResolver
{
	public function __construct(
		private LocaleResolver $locales,
		private ObjectPropertyDefinitionRegistry $definitions,
		private ApplicationRegistry $applications,
		private Security $security,
	) {
	}

	public function resolve(DbObject $object, ObjectProperty $property): ?ResolvedPropertyValue
	{
		$localized = $property->getLocalizedValue();
		if (null === $localized) {
			return null;
		}

		$objectType = $object->getType() ?? strtolower((new \ReflectionClass($object))->getShortName());
		$propertyName = $property->getName();
		$definition = null === $propertyName ? null : (
			$this->definitions->get($objectType, $propertyName) ??
			$this->definitions->get($object::class, $propertyName)
		);
		$site = SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null;
		$application = $site instanceof Site && $this->applications->hasCurrentApplication() ?
			$this->applications->getCurrent() :
			null;
		$user = $this->security->getUser();
		$userLocale = $user instanceof User ? $user->getPreferredLocale() : null;
		$objectLocale = $object->getDefaultLocale() ?? $definition?->defaultLocale;
		$siteLocale = $site instanceof Site ? $site->getDefaultLocale() : null;
		$applicationLocale = $application instanceof Application ? $application->getDefaultLocale() : null;
		$scopedLocales = $object->getSupportedContentLocales() ??
			($site instanceof Site ? $site->getSupportedContentLocales() : null) ??
			($application instanceof Application ? $application->getSupportedContentLocales() : null);
		$availableLocales = array_values(array_filter(
			$localized->getLocales(),
			fn (string $locale): bool => (null === $definition || $definition->supportsLocale($locale)) &&
				(null === $scopedLocales || $this->isAllowedByScope($locale, $scopedLocales)),
		));

		if ([] !== $localized->getSegments()) {
			return new ResolvedPropertyValue($localized->getSegments(), null, $localized);
		}

		$selectedLocale = $this->locales->resolveContentLocale(
			$availableLocales,
			$userLocale,
			$objectLocale,
			$siteLocale,
			$applicationLocale,
		);

		return new ResolvedPropertyValue(
			$localized->getTranslations()[$selectedLocale->getTag()] ?? null,
			$selectedLocale,
			$localized,
		);
	}

	/** @param list<LocaleTag> $allowed */
	private function isAllowedByScope(string $locale, array $allowed): bool
	{
		return $this->locales->isLocaleAllowed($locale, $allowed);
	}
}
