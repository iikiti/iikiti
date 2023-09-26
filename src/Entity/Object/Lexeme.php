<?php
namespace iikiti\CMS\Entity\Object;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Doctrine\DiscriminatorValue;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\LexemeRepository;

#[ORM\Entity(repositoryClass: LexemeRepository::class)]
#[ORM\Table(name: "objects")]
#[DiscriminatorValue('lexeme')]
class Lexeme extends DbObject
{

}
