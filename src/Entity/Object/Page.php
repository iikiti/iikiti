<?php
namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\PageRepository;

#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\Table(name: "objects")]
class Page extends DbObject
{
    const DEFAULT_TYPE = 'page';

}
