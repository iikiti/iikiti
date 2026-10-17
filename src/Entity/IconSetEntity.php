<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\IconSetRepository;

#[ORM\Entity(repositoryClass: IconSetRepository::class)]
class IconSetEntity extends DbObject
{
	public const SITE_SPECIFIC = false;

	#[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
	private ?string $slug = null;

	#[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
	private ?string $name = null;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
	private ?\DateTimeImmutable $updatedAt = null;

	/** @var Collection<int,IconEntity> */
	#[ORM\OneToMany(mappedBy: 'iconSet', targetEntity: IconEntity::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
	private Collection $icons;

	public function __construct(string $slug, string $name)
	{
		$this->slug = $slug;
		$this->name = $name;
		$this->updatedAt = new \DateTimeImmutable();
		$this->icons = new ArrayCollection();
		$this->setProperties(new ArrayCollection());
		$this->setSiteId(0);
		$this->setCreatorId(0);
		$this->setCreatedDate(new \DateTimeImmutable());
	}

	public function getSlug(): string
	{
		return $this->slug ?? throw new \LogicException('Icon set has no slug.');
	}

	public function getName(): string
	{
		return $this->name ?? throw new \LogicException('Icon set has no name.');
	}

	public function rename(string $name): void
	{
		$this->name = $name;
		$this->updatedAt = new \DateTimeImmutable();
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		$createdDate = $this->getCreatedDate();
		if (!$createdDate instanceof \DateTimeImmutable) {
			throw new \LogicException('Icon set has no creation date.');
		}

		return $createdDate;
	}

	public function getUpdatedAt(): \DateTimeImmutable
	{
		return $this->updatedAt ?? throw new \LogicException('Icon set has no update date.');
	}

	public function addIcon(IconEntity $icon): void
	{
		if (!$this->icons->contains($icon)) {
			$this->icons->add($icon);
		}
	}
}
