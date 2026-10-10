<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\IconRepository;

/**
 * A single icon inside an admin-managed {@see IconSetEntity}. `svg` is always the
 * output of {@see \iikiti\CMS\Web\Icon\SvgSanitizer}: raw, unsanitised markup is never
 * stored.
 */
#[ORM\Entity(repositoryClass: IconRepository::class)]
#[ORM\Table(name: 'icons')]
#[ORM\UniqueConstraint(name: 'uniq_icon_set_name', columns: ['icon_set_id', 'name'])]
class IconEntity
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: IconSetEntity::class)]
	#[ORM\JoinColumn(name: 'icon_set_id', nullable: false, onDelete: 'CASCADE')]
	private IconSetEntity $iconSet;

	/** Icon name within its set: lowercase letters, digits, dashes. */
	#[ORM\Column(type: Types::STRING, length: 64)]
	private string $name;

	/** Sanitised SVG markup (see SvgSanitizer). */
	#[ORM\Column(type: Types::TEXT)]
	private string $svg;

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	public function __construct(IconSetEntity $iconSet, string $name, string $sanitisedSvg)
	{
		$this->iconSet = $iconSet;
		$this->name = $name;
		$this->svg = $sanitisedSvg;
		$this->createdAt = new \DateTimeImmutable();
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getIconSet(): IconSetEntity
	{
		return $this->iconSet;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getSvg(): string
	{
		return $this->svg;
	}
}
