<?php
namespace iikiti\CMS\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\ObjectPropertyRepository;

#[ORM\Entity(repositoryClass: ObjectPropertyRepository::class)]
#[ORM\Table(name: "object_properties")]
class ObjectProperty
{

	#[ORM\ManyToOne(targetEntity: DbObject::class, inversedBy: 'id')]
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

}
