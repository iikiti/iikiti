<?php
namespace iikiti\CMS\Entity;

use iikiti\CMS\Entity\Object\Site;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ObjectRepository::class)]
#[ORM\Table(name: "objects", schema: "iikiti_iikiti")]
#[ORM\MappedSuperclass()]
class DbObject
{

    #[ORM\Id()]
    #[ORM\GeneratedValue()]
    #[ORM\Column(type: "bigint")]
    protected $id;

    #[ORM\Column(type: "datetime")]
    private $created_date;

    #[ORM\Column(type: "string")]
    private $type;

    #[ORM\Column(type: "bigint")]
    private $creator_id;

    #[ORM\Column(type: "bigint")]
    private $site_id;

    #[ORM\Column(type: "jsonb")]
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

    public function getRevisionId(): int {
        return $this->revision_id;
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
