<?php
namespace iikiti\CMS\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ObjectRevisionRepository::class)]
#[ORM\Table(name: "revisions", schema: "iikiti_iikiti")]
#[ORM\MappedSuperclass()]
class ObjectRevision extends DbObject
{

    #[ORM\Column(type: "bigint")]
    protected $id;

    #[ORM\Id()]
    #[ORM\GeneratedValue()]
    #[ORM\Column(type: "bigint")]
    private $revision_id;

}
