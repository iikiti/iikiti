<?php

namespace iikiti\CMS\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\InheritanceType;
use iikiti\CMS\Repository\ObjectRepository;

#[ORM\Entity(repositoryClass: ObjectRepository::class)]
#[ORM\Table(name: 'objects')]
#[ORM\MappedSuperclass()]
#[InheritanceType('SINGLE_TABLE')]
#[DiscriminatorColumn(name: 'type', type: 'string')]
class DbObject
{
	/** @var bool SITE_SPECIFIC */
	public const SITE_SPECIFIC = true;

	#[ORM\Id()]
	#[ORM\GeneratedValue()]
	#[ORM\Column(type: Types::BIGINT)]
	protected int|string|null $id = null;
	#[ORM\Id()]
	#[ORM\GeneratedValue()]
	#[ORM\Column(type: Types::BIGINT)]
	protected int|string|null $id = null;

	#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
	private ?\DateTimeInterface $created_date = null;
	#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
	private ?\DateTimeInterface $created_date = null;

	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $creator_id = null;

	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $site_id = null;

	private ?string $type = null;

	/** @psalm-suppress PropertyNotSetInConstructor
	 * @var Collection<string,ObjectProperty> $properties
	 */
	#[ORM\OneToMany(targetEntity: ObjectProperty::class, mappedBy: 'object', indexBy: 'name')]
	private Collection $properties;

	public function __construct()
	{
	}

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

	public function getCreatorId(): int|string|null
	{
		return $this->creator_id;
	}

	public function getContent(): null|array
	{
		return [];
	}

	public function getProperties(): Collection
	{
		return $this->properties;
	}
}
