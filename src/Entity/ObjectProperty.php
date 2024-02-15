<?php

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\ObjectPropertyRepository;

/**
 * @psalm-suppress MissingConstructor
 */
#[ORM\Entity(repositoryClass: ObjectPropertyRepository::class)]
#[ORM\Table(name: 'object_properties')]
#[ORM\UniqueConstraint(
	name: 'object_properties_object_key',
	columns: ['object_id', 'name']
)]
#[ORM\Index(
	name: 'object_properties_PK',
	columns: ['id', 'object_id', 'name', 'created' /* DESC */]
)]
#[ORM\Index(
	name: 'object_properties_value',
	columns: ['object_id', 'name', '(cast(`value_array` as char(255) array))', 'created' /* DESC */]
)]
class ObjectProperty
{
	#[ORM\ManyToOne(targetEntity: DbObject::class, inversedBy: 'properties')]
	#[ORM\JoinColumn(name: 'object_id', referencedColumnName: 'id')]
	private ?DbObject $object;

	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $object_id;

	#[ORM\Id()]
	#[ORM\GeneratedValue()]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|float $id;

	#[ORM\Column(type: Types::STRING)]
	private string|null $name;

	#[ORM\Column(type: Types::JSON)]
	private int|float|string|array|null $value;

	#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
	private ?\DateTimeInterface $created;

	#[ORM\OneToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(name: 'creator_id', referencedColumnName: 'id')]
	private ?User $creator;

	#[ORM\Column(
		type: Types::JSON,
		columnDefinition: '('.
			"(case when (json_type(`value`) <> _utf8mb4'OBJECT') then ".
				"ifnull(json_extract(`value`,_utf8mb4'$[*]'),json_array(`value`)) else ".
				'NULL '.
			'end))',
		insertable: false,
		updatable: false,
		generated: 'ALWAYS'
	)]
	private array|null $value_array;

	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|float $creator_id;

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
