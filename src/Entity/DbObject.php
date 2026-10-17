<?php

namespace iikiti\CMS\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Trait\PropertiedTrait;

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
#[ORM\Index(name: 'IDX_ADC517D4E10C7F9A', columns: ['api_token_user_id'])]
#[ORM\Index(name: 'IDX_ADC517D448D16F3B', columns: ['icon_set_id'])]
#[ORM\UniqueConstraint(name: 'uniq_api_token_token', columns: ['token'])]
#[ORM\UniqueConstraint(name: 'uniq_object_icon_set_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_object_icon_set_name', columns: ['icon_set_id', 'icon_name'])]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ApiResource]
class DbObject
{
	use PropertiedTrait;

	/** @var bool SITE_SPECIFIC */
	public const SITE_SPECIFIC = true;

	#[ORM\Id()]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	protected int|string|null $id;

	#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $created_date;

	#[ORM\Column(name: 'site_id', type: Types::BIGINT, options: ['unsigned' => true, 'default' => 0])]
	private int|string $site = 0;

	private ?string $type = null;

	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $creator_id;

	/** @var Collection<string,ObjectProperty> */
	#[ORM\OneToMany(
		targetEntity: ObjectProperty::class,
		mappedBy: 'object',
		indexBy: 'name',
		cascade: ['persist', 'remove'],
		orphanRemoval: true
	)]
	private Collection $properties;

	public function getId(): int|string|null
	{
		return $this->id ?? null;
	}

	public function getSiteId(): int|string
	{
		return $this->site;
	}

	public function getLinkedSiteId(): int|string|null
	{
		return $this->site;
	}

	public function setSiteId(int|string $siteId): void
	{
		$this->site = $siteId;
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

	public function setSite(Site $site): void
	{
		$siteId = $site->getId();
		if (null === $siteId) {
			throw new \LogicException('Cannot assign an unpersisted site to an object.');
		}

		$this->setSiteId($siteId);
	}

	public function setCreatorId(int|string $creatorId): void
	{
		$this->creator_id = $creatorId;
	}

	/**
	 * created_date is NOT NULL and has no default; set it when creating an object.
	 */
	public function setCreatedDate(\DateTimeImmutable $createdDate): void
	{
		$this->created_date = $createdDate;
	}

	#[\Override]
	public function getProperties(): Collection
	{
		return $this->properties;
	}

	#[\Override]
	public function setProperties(Collection $properties): void
	{
		$this->properties = $properties;
	}

	#[\Override]
	public function setProperty(string $name, mixed $value): void
	{
		$isProperty = $value instanceof ObjectProperty;
		$property = $isProperty ? $value :
			($this->getProperties()->get($name) ?? new ObjectProperty());
		$property->setName($name);
		$property->setObject($this);
		if (false == $isProperty) {
			$property->setValue($value);
		}
		$this->getProperties()->set($name, $property);
	}
}
