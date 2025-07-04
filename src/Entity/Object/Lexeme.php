<?php

namespace iikiti\CMS\Entity\Object;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\LexemeRepository;

/**
 * Lexeme entity.
 */
#[ORM\Entity(repositoryClass: LexemeRepository::class)]
#[ORM\Table(name: 'objects')]
#[ApiResource]
class Lexeme extends DbObject
{
}
