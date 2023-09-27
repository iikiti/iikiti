<?php
namespace iikiti\CMS\Entity;

use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\ObjectRevisionRepository;

#[ORM\Entity(repositoryClass: ObjectRevisionRepository::class)]
#[ORM\Table(name: "revisions", schema: "iikiti_iikiti")]
#[ORM\MappedSuperclass()]
class ObjectRevision extends DbObject
{

    #[ORM\Column(type: "bigint")]
    protected ?int $id = null;

    #[ORM\Id()]
    #[ORM\GeneratedValue()]
    #[ORM\Column(type: "bigint")]
    private ?int $revision_id = null;

}
