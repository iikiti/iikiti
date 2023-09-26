<?php
namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\LexemeRepository;

#[ORM\Entity(repositoryClass: LexemeRepository::class)]
#[ORM\Table(name: "objects")]
class Lexeme extends DbObject
{

}
