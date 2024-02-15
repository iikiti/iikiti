<?php

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\ObjectPropertyRepository;

/**
 * @template T of DbObject|null
 *
 * @psalm-suppress MissingConstructor
 */
#[ORM\Entity(repositoryClass: ObjectPropertyRepository::class)]
#[ORM\Table(name: 'object_properties')]
#[ORM\UniqueConstraint(
	name: 'object_properties_UN',
	columns: ['object_id', 'name', 'created' /* DESC */]
)]
#[ORM\Index(
	name: 'object_properties_PK',
	columns: ['id', 'object_id', 'name', 'created' /* DESC */]
)]
#[ORM\Index(
	name: 'json_value_char',
	columns: ['object_id', 'name', 'created' /* DESC */, '(cast(`value_array` as char(255) array))']
)]
class ObjectProperty
{
	/** @var T $value */
	#[ORM\ManyToOne(targetEntity: DbObject::class, inversedBy: 'properties')]
	private ?DbObject $object;

	#[ORM\Id()]
	#[ORM\GeneratedValue()]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id;

	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $object_id;

	#[ORM\Column(type: Types::STRING)]
	private string|null $name;

	#[ORM\Column(type: Types::JSON)]
	private int|float|string|array|null $value;

	#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
	private ?\DateTimeInterface $created;

	#[ORM\Column(
		type: Types::JSON,
		columnDefinition: '('.
			"(case when (json_type(`value`) <> _utf8mb4'OBJECT') then ".
				'`value` else '.
				'NULL '.
			'end))',
		insertable: false,
		updatable: false,
		generated: 'ALWAYS'
	)]
	private string|int|float|null $value_array;

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
