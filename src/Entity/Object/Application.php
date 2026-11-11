<?php

namespace iikiti\CMS\Entity\Object;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\ApplicationRepository;
use iikiti\CMS\Trait\MfaConfigurableTrait;
use iikiti\CMS\Trait\ConfigurableTrait;
use iikiti\CMS\Trait\LocaleConfigurableTrait;

/**
 * Application entity
 * Top level above sites.
 */
#[ORM\Entity(repositoryClass: ApplicationRepository::class)]
#[ORM\Table(name: 'object_lookup')]
#[ApiResource]
class Application extends DbObject
{
	use MfaConfigurableTrait;
	use ConfigurableTrait;
	use LocaleConfigurableTrait;

	public const SITE_SPECIFIC = false;

	/**
	 * @return string[]
	 */
	public function getEnabledPlugins(): array
	{
		$requiredPlugins = [
			'iikiti/components',
		];

		return array_merge(
			$requiredPlugins,
			$this->getConfiguration()->getActivePlugins()
		);
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
