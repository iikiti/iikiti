<?php
namespace iikiti\CMS\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\InheritanceType;
use iikiti\CMS\Repository\ObjectRepository;

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
    protected ?int $id = null;

    #[ORM\Column(type: "datetime")]
    private ?DateTimeInterface $created_date = null;

    #[ORM\Column(type: "bigint")]
    private ?int $creator_id = null;

    #[ORM\Column(type: "bigint")]
    private ?int $site_id = null;

    #[ORM\Column(type: "json")]
    private null|array $content_json = null;

	private ?string $type = null;

    public function __construct() {
        
    }

    public function getId(): ?int {
        return $this->id;
    }

    public function getSiteId(): ?int {
        return $this->site_id;
    }

    public function getType(): ?string {
        return $this->type;
    }

    public function getCreatedDate(): ?DateTimeInterface {
        return $this->created_date;
    }

    public function getCreatorId(): ?int {
        return $this->creator_id;
    }

    public function getContent(): null|array {
        return $this->content_json;
    }

}
