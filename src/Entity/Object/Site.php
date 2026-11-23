<?php

namespace iikiti\CMS\Entity\Object;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Trait\ConfigurableTrait;
use iikiti\CMS\Trait\LocaleConfigurableTrait;
use iikiti\CMS\Trait\MfaConfigurableTrait;

/**
 * Site entity
 * Handles actual websites by domain/URL.
 */
#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\Table(name: 'object_lookup')]
#[ApiResource]
class Site extends DbObject
{
	use MfaConfigurableTrait;
	use ConfigurableTrait;
	use LocaleConfigurableTrait;

	public const SITE_SPECIFIC = false;
	public const DOMAIN_PROPERTY_KEY = 'domain';

	/**
	 * Returns stored active plugin slugs merged with core defaults.
	 *
	 * Runtime manifest-aware activation checks (honoring `activation_mode` and
	 * `scope`) should prefer {@see \iikiti\CMS\Plugin\PluginActivationChecker}.
	 *
	 * @return string[]
	 */
	public function getEnabledPlugins(): array
	{
		$configuration = $this->getConfiguration();
		$requiredPlugins = ['iikiti/components'];
		$defaultActive = ['iikiti-content-optimization', 'forma-2026'];

		return array_values(array_unique(array_merge(
			$requiredPlugins,
			$configuration->getActivePlugins($defaultActive),
		)));
	}

	/**
	 * @deprecated use getEnabledPlugins(). "Extension" is a legacy alias for "plugin".
	 *
	 * @return string[]
	 */
	public function getEnabledExtensions(): array
	{
		return $this->getEnabledPlugins();
	}
}
