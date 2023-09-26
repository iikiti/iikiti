<?php
namespace iikiti\CMS\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\InheritanceType;
use iikiti\CMS\Entity\Object\Site;

#[ORM\Entity(repositoryClass: ObjectRepository::class)]
#[ORM\Table(name: "objects")]
#[ORM\MappedSuperclass()]
#[InheritanceType('SINGLE_TABLE')]
#[DiscriminatorColumn(name: 'type', type: 'string')]
class DbObject
{

    #[ORM\Id()]
    #[ORM\GeneratedValue()]
    #[ORM\Column(type: "bigint")]
    protected $id;

    #[ORM\Column(type: "datetime")]
    private $created_date;

    #[ORM\Column(type: "bigint")]
    private $creator_id;

    #[ORM\Column(type: "bigint")]
    private $site_id;

    #[ORM\Column(type: "json")]
    private $content_json;

    public function __construct() {
        
    }

    public function getId(): int {
        return $this->id;
    }

    public function getSiteId(): ?Site {
        return $this->site_id;
    }

    public function getType(): string {
        return $this->type;
    }

    public function getCreatedDate(): \DateTime {
        return $this->created_date;
    }

    public function getCreatorId(): int {
        return $this->creator_id;
    }

    public function getContent(): object|array {
        return $this->content_json;
    }

}
