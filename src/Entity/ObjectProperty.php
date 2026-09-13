<?php

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\ObjectPropertyRepository;

/**
 * Database property entity
 * Allows access to object metadata.
 *
 * @psalm-suppress MissingConstructor
 */
#[ORM\Entity(repositoryClass: ObjectPropertyRepository::class)]
#[ORM\Table(name: 'object_properties')]
class ObjectProperty
{
	/** @var int|string|null */
	#[ORM\Id()]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id;

	#[ORM\ManyToOne(targetEntity: DbObject::class, inversedBy: 'properties')]
	#[ORM\JoinColumn(name: 'object_id', referencedColumnName: 'id')]
	private ?DbObject $object;

	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string $object_id;

	#[ORM\Column(type: Types::STRING)]
	private string $name;

	/**
	 * @var int|float|string|array<int|string,mixed>|null
	 */
	#[ORM\Column(type: Types::JSON)]
	private int|float|string|array|null $value;

	#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $created;

	#[ORM\OneToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(name: 'creator_id', referencedColumnName: 'id')]
	private ?User $creator;

	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string $creator_id;

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(?string $name): void
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

	public function getCreator(): ?User {
		return $this->creator;
	}

	public function getCreationDate(): ?\DateTimeInterface
	{
		return $this->created;
	}

	public function setCreationDate(\DateTimeImmutable $created): void
	{
		$this->created = $created;
	}

	public function setObject(DbObject $object): void
	{
		$this->object = $object;
	}

	public function getId(): int|float
	{
		return $this->id;
	}

	public function getObject(): ?DbObject
	{
		return $this->object;
	}
}
