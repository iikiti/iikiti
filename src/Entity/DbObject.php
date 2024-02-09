<?php

namespace iikiti\CMS\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\InheritanceType;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Trait\PropertiedTrait;

#[ORM\Entity(repositoryClass: ObjectRepository::class)]
#[ORM\Table(name: 'objects')]
#[ORM\MappedSuperclass()]
#[InheritanceType('SINGLE_TABLE')]
#[DiscriminatorColumn(name: 'type', type: 'string')]
class DbObject
{
	use PropertiedTrait;

	/** @var bool SITE_SPECIFIC */
	public const SITE_SPECIFIC = true;

	#[ORM\Id()]
	#[ORM\GeneratedValue()]
	#[ORM\Column(type: Types::BIGINT)]
	protected int|string|null $id = null;

	#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
	private ?\DateTimeInterface $created_date = null;

	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $creator_id = null;

	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $site_id = null;

	private ?string $type = null;

	protected ?string $repositoryClass = null;

	/** @psalm-suppress PropertyNotSetInConstructor */
	protected SiteRegistry $siteRegistry;

	#[ORM\OneToMany(
		targetEntity: ObjectProperty::class,
		mappedBy: 'object',
		indexBy: 'name',
		cascade: ['persist', 'remove'],
		orphanRemoval: true
	)]
	/** @var Collection<string,ObjectProperty<mixed>> */
	private Collection $properties;

	public function __construct()
	{
		$this->properties = new ArrayCollection();
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

	public function getProperties(): Collection
	{
		return $this->properties;
	}

	public function setProperties(Collection $properties): void
	{
		/** @var ObjectProperty $property */
		foreach ($properties as $property) {
			$name = $property->getName();
			if (!is_string($name) || '' == $name) {
				throw new \Exception('Property name is missing.');
			}
			$this->setProperty($name, $property);
		}
	}

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

	public function setSiteRegistry(SiteRegistry $siteRegistry): void
	{
		$this->siteRegistry = $siteRegistry;
	}

	public function getRepositoryClass(): ?string
	{
		if (null === $this->repositoryClass) {
			$refClass = new \ReflectionClass($this);
			$attrs = $refClass->getAttributes();
			foreach ($attrs as $attr) {
				if (ORM\Entity::class == $attr->getName()) {
					$this->repositoryClass = $attr->getArguments()['repositoryClass'] ??
						$attr->getArguments()[0];
					break;
				}
			}
		}

		return $this->repositoryClass;
	}
}
