<?php
namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\SiteUserLinkRepository;

#[ORM\Entity(repositoryClass: SiteUserLinkRepository::class)]
#[ORM\Table(name: "objects")]
class SiteUserLink extends DbObject
{

    public function getRoles(): array|\ArrayAccess
    {
        // TODO: Implement getRoles() method.
        return array_merge($this->roles, ['ROLE_USER']);
    }

    public function getUserId()
    {
        return $this->getMeta()->first()->getContent()->userId;
    }

    public function __serialize(): array
    {
        // TODO: Implement serialize() method.
        return [];
    }

    public function __unserialize(array $data): void
    {
        // TODO: Implement unserialize() method.
    }
}
