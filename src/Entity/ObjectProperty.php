<?php

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\ObjectPropertyRepository;

/**
 * @template T of DbObject|null
 */
#[ORM\Entity(repositoryClass: ObjectPropertyRepository::class)]
#[ORM\Table(name: 'object_properties')]
class ObjectProperty
{
	/** @var T $value */
	#[ORM\ManyToOne(targetEntity: DbObject::class, inversedBy: 'properties')]
	private ?DbObject $object = null;

	#[ORM\Id()]
	#[ORM\GeneratedValue()]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $object_id = null;

	#[ORM\Column(type: Types::STRING)]
	private string|null $name = null;

	#[ORM\Column(type: Types::JSON)]
	private int|float|string|array|null $value = null;

	#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
	private ?\DateTimeInterface $created = null;

	public function getName(): string|null
	{
		return $this->name;
	}

	public function setName(string|null $name): void
	{
		$this->name = $name;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function setValue(mixed $value): void
	{
		$this->value = $value;
	}

	public function getCreationDate(): ?\DateTimeInterface
	{
		return $this->created;
	}

	public function setCreationDate(\DateTimeInterface $created): void
	{
		$this->created = $created;
	}

	public function setObject(DbObject|int $object): void
	{
		$this->object_id = $object instanceof DbObject ? $object->getId() : $object;
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	/**
	 * @return T
	 */
	public function getObject(): ?DbObject
	{
		return $this->object;
	}
}
