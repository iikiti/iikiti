<?php
namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\Configuration;

#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\Table(name: "objects")]
class Site extends DbObject
{

	const SITE_SPECIFIC = false;

    public function getConfiguration(): Configuration {
        return new Configuration((object) $this->getContent());
    }

	/**
	 * @return string[]
	 */
    public function getEnabledExtensions(): array {
        $requiredExtensions = [
            'iikiti/components/ComponentsBundle'
        ];
        return array_merge(
            $requiredExtensions,
            $this->getConfiguration()->getActiveExtensions()
        );
    }

}
