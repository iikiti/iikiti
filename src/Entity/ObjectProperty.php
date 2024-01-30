<?php

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\ObjectPropertyRepository;

/**
 * @template T of int|float|string|array|null
 */
#[ORM\Entity(repositoryClass: ObjectPropertyRepository::class)]
#[ORM\Table(name: 'object_properties')]
class ObjectProperty
{
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

	/** @var T $value */
	#[ORM\Column(type: Types::JSON)]
	private int|float|string|array|null $value = null;

	#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeInterface $created;

	public function __construct(
		DbObject $dbObject = null,
		string $name = null,
		mixed $value = null
	) {
		$this->created = new \DateTimeImmutable();
		$this->object = $dbObject;
		$this->setName($name);
		$this->setValue($value);
	}

	public function getName(): string|null
	{
		return $this->name;
	}

	public function setName(string|null $name): void
	{
		$this->name = $name;
	}

	/**
	 * @return T
	 */
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

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getObject(): ?DbObject
	{
		return $this->object;
	}
}
