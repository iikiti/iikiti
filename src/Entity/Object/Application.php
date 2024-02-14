<?php

namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\ApplicationRepository;
use iikiti\CMS\Trait\ConfigurableTrait;
use iikiti\CMS\Trait\MfaConfigurableTrait;
use iikiti\MfaBundle\Authentication\Interface\MfaPreferencesInterface;

#[ORM\Entity(repositoryClass: ApplicationRepository::class)]
#[ORM\Table(name: 'objects')]
class Application extends DbObject implements MfaPreferencesInterface
{
	use MfaConfigurableTrait;
	use ConfigurableTrait;

	public const SITE_SPECIFIC = false;
	public const PROPERTY_KEY = 'application';

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
