<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\IconRepository;

#[ORM\Entity(repositoryClass: IconRepository::class)]
class IconEntity extends DbObject
{
	public const SITE_SPECIFIC = false;

	#[ORM\ManyToOne(targetEntity: IconSetEntity::class, inversedBy: 'icons')]
	#[ORM\JoinColumn(name: 'icon_set_id', nullable: true, onDelete: 'CASCADE')]
	private ?IconSetEntity $iconSet = null;

	#[ORM\Column(name: 'icon_name', type: Types::STRING, length: 64, nullable: true)]
	private ?string $name = null;

	#[ORM\Column(type: Types::TEXT, nullable: true)]
	private ?string $svg = null;

	public function __construct(IconSetEntity $iconSet, string $name, string $sanitisedSvg)
	{
		$this->iconSet = $iconSet;
		$this->name = $name;
		$this->svg = $sanitisedSvg;
		$this->setProperties(new ArrayCollection());
		$this->setSiteId(0);
		$this->setCreatorId(0);
		$this->setCreatedDate(new \DateTimeImmutable());
		$iconSet->addIcon($this);
	}

	public function getIconSet(): IconSetEntity
	{
		return $this->iconSet ?? throw new \LogicException('Icon has no set.');
	}

	public function getName(): string
	{
		return $this->name ?? throw new \LogicException('Icon has no name.');
	}

	public function getSvg(): string
	{
		return $this->svg ?? throw new \LogicException('Icon has no SVG content.');
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		$createdDate = $this->getCreatedDate();
		if (!$createdDate instanceof \DateTimeImmutable) {
			throw new \LogicException('Icon has no creation date.');
		}

		return $createdDate;
	}
}
