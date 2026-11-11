<?php

namespace iikiti\CMS\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Trait\LocaleConfigurableTrait;
use iikiti\CMS\Trait\PropertiedTrait;

/**
 * Database object entity, mapped through object_lookup to the partitioned objects table.
 * High-level class that all other objects (like applications, pages, sites,
 * users, etc) should extend from.
 * Provides convenience methods.
 *
 * @psalm-suppress MissingConstructor
 */
#[ORM\Entity(repositoryClass: ObjectRepository::class)]
#[ORM\Table(name: 'object_lookup')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ApiResource]
class DbObject
{
	use PropertiedTrait;
	use LocaleConfigurableTrait;

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
		if (!isset($this->properties)) {
			$this->properties = new ArrayCollection();
		}

		return $this->properties;
	}

	#[\Override]
	public function setProperties(Collection $properties): void
	{
		foreach ($this->getProperties() as $name => $property) {
			if ($property->isSecret() && $properties->get($name) !== $property) {
				throw new \LogicException('Secret properties can only be changed through SecretsApi.');
			}
		}
		foreach ($properties as $property) {
			if ($property->isSecret() && $this->getProperties()->get($property->getName()) !== $property) {
				throw new \LogicException('Secret properties can only be changed through SecretsApi.');
			}
		}

		$this->properties = $properties;
	}

	#[\Override]
	public function setProperty(string $name, mixed $value): void
	{
		$isProperty = $value instanceof ObjectProperty;
		$existingProperty = $this->getProperties()->get($name);
		if (($existingProperty instanceof ObjectProperty && $existingProperty->isSecret()) || ($isProperty && $value->isSecret())) {
			throw new \LogicException('Secret properties can only be changed through SecretsApi.');
		}

		$property = $isProperty ? $value :
			($existingProperty ?? new ObjectProperty());
		$property->setName($name);
		$property->setObject($this);
		if (false == $isProperty) {
			$property->setValue($value);
		}
		$this->getProperties()->set($name, $property);
	}
}
