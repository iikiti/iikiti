<?php

namespace iikiti\CMS\Entity\Object;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\PageRepository;

/**
 * Page entity.
 */
#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\Table(name: 'object_lookup')]
#[ApiResource]
class Page extends DbObject
{
}
