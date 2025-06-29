<?php

namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\ApplicationRepository;
use iikiti\CMS\Trait\ConfigurableTrait;
use iikiti\CMS\Trait\MfaConfigurableTrait;

/**
 * Application entity
 * Top level above sites.
 */
#[ORM\Entity(repositoryClass: ApplicationRepository::class)]
#[ORM\Table(name: 'objects')]
class Application extends DbObject
{
	use MfaConfigurableTrait;
	use ConfigurableTrait;

	public const SITE_SPECIFIC = false;

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
