<?php
namespace iikiti\CMS\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\ObjectPropertyRepository;

#[ORM\Entity(repositoryClass: ObjectPropertyRepository::class)]
#[ORM\Table(name: "object_properties", options: ["where" => "(1 = 1)"])]
class ObjectProperty
{

	#[ORM\ManyToOne(targetEntity: DbObject::class, inversedBy: 'properties')]
	private ?DbObject $object;

	#[ORM\Id()]
    #[ORM\GeneratedValue()]
    #[ORM\Column(type: Types::BIGINT)]
	private int $id;

	#[ORM\Column(type: Types::BIGINT)]
	private int $object_id;

	#[ORM\Column(type: Types::STRING)]
	private string $name;

	#[ORM\Column(type: Types::JSON)]
	private int|float|string|array $value;

	#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
	private DateTimeInterface $created;

	public function getName(): string {
		return $this->name;
	}

	public function getValue(): int|float|string|array {
		return $this->value;
	}

	public function getCreationDate(): DateTimeInterface {
		return $this->created;
	}

	public function getId(): int {
		return $this->id;
	}

	public function getObject(): ?DbObject {
		return $this->object;
	}

}
