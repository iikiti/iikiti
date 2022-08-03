<?php
namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\Configuration;

#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\Table(name: "objects", schema: "iikiti_iikiti")]
class Site extends DbObject
{
    const DEFAULT_TYPE = 'site';

    public function getSite(): ?Site {
        return null;
    }

    public function getByDomain(string $domain) {

    }

    public function getConfiguration(): Configuration {
        return new Configuration((object) $this->getContent());
    }

    public function getEnabledExtensions() {
        $requiredExtensions = [
            'iikiti/components/ComponentsBundle'
        ];
        return array_merge(
            $requiredExtensions,
            $this->getConfiguration()->getActiveExtensions()
        );
    }

}
