<?php

namespace iikiti\CMS\Entity\Object;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Trait\ConfigurableTrait;
use iikiti\CMS\Trait\MfaConfigurableTrait;

/**
 * Site entity
 * Handles actual websites by domain/URL.
 */
#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\Table(name: 'objects')]
#[ApiResource]
class Site extends DbObject
{
	use MfaConfigurableTrait;
	use ConfigurableTrait;

	public const SITE_SPECIFIC = false;
	public const DOMAIN_PROPERTY_KEY = 'domain';

	/**
	 * @return string[]
	 */
	public function getEnabledExtensions(): array
	{
		$requiredExtensions = [
			'iikiti/components/ComponentsBundle',
		];

		return array_merge(
			$requiredExtensions,
			$this->getConfiguration()->getActiveExtensions()
		);
	}
}
