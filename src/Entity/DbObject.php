<?php

namespace iikiti\CMS\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\InheritanceType;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Trait\PropertiedTrait;
use Override;

/**
 * Database object entity
 * High-level class that all other objects (like applications, pages, sites,
 * users, etc) should extend from.
 * Provides convenience methods.
 *
 * @psalm-suppress MissingConstructor
 */
#[ORM\Entity(repositoryClass: ObjectRepository::class)]
#[ORM\Table(name: 'objects')]
#[ORM\MappedSuperclass()]
#[InheritanceType('SINGLE_TABLE')]
#[DiscriminatorColumn(name: 'type', type: 'string')]
#[ORM\Index(
	name: 'objects_type_IDX',
	columns: ['type', 'site_id', 'creator_id', 'created_date' /* DESC */]
)]
#[ORM\UniqueConstraint(name: 'objects_type_IDX', columns: ['id', 'site_id'])]
class DbObject
{
	use PropertiedTrait;

	/** @var bool SITE_SPECIFIC */
	public const SITE_SPECIFIC = true;

	#[ORM\Id()]
	#[ORM\GeneratedValue()]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	protected int|string|null $id;

	#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
	private ?\DateTimeInterface $created_date;

	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $creator_id;

	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $site_id;

	private ?string $type;

	#[ORM\OneToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(name: 'creator_id', referencedColumnName: 'id')]
	private ?User $creator;

	#[ORM\OneToMany(
		targetEntity: ObjectProperty::class,
		mappedBy: 'object',
		indexBy: 'name',
		cascade: ['persist', 'remove'],
		orphanRemoval: true
	)]
	/** @var Collection<string,ObjectProperty<mixed>> */
	private Collection $properties;

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getLinkedSiteId(): int|string|null
	{
		return $this->site_id;
	}

	public function getType(): ?string
	{
		return $this->type;
	}

	public function getCreatedDate(): ?\DateTimeInterface
	{
		return $this->created_date;
	}

	public function getCreator(): ?User
	{
		return $this instanceof User ? null : $this->creator;
	}

	public function getCreatorId(): int|string|null
	{
		return $this->creator_id;
	}

	#[Override]
	public function getProperties(): Collection
	{
		return $this->properties;
	}

	#[Override]
	public function setProperties(Collection $properties): void
	{
		foreach ($properties as $property) {
			$name = $property->getName();
			if (!is_string($name) || '' == $name) {
				throw new \Exception('Property name is missing.');
			}
			$this->setProperty($name, $property);
		}
	}

	#[Override]
	public function setProperty(string $name, mixed $value): void
	{
		$isProperty = $value instanceof ObjectProperty;
		$property = $isProperty ? $value :
			($this->getProperties()->get($name) ?? new ObjectProperty());
		if ($property->getName() != $name) {
			$property->setName($name);
		}
		$property->setObject($this);
		if (false == $isProperty) {
			$property->setValue($value);
		}
		$this->getProperties()->set($name, $property);
	}
}
