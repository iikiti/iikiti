<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\IconSetRepository;

/**
 * An admin-managed icon set: a named, ordered collection of sanitised SVG icons that
 * the icon block can pick from. The bundled Lucide set is not stored here; it ships
 * with the code (see {@see \iikiti\CMS\Web\Icon\LucideIconSet}).
 */
#[ORM\Entity(repositoryClass: IconSetRepository::class)]
#[ORM\Table(name: 'icon_sets')]
#[ORM\UniqueConstraint(name: 'uniq_icon_set_slug', columns: ['slug'])]
class IconSetEntity
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id = null;

	/** Stable identifier used in content (e.g. `brand`). Lowercase letters, digits, dashes. */
	#[ORM\Column(type: Types::STRING, length: 64)]
	private string $slug;

	#[ORM\Column(type: Types::STRING, length: 128)]
	private string $name;

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $updatedAt;

	public function __construct(string $slug, string $name)
	{
		$this->slug = $slug;
		$this->name = $name;
		$this->createdAt = new \DateTimeImmutable();
		$this->updatedAt = new \DateTimeImmutable();
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getSlug(): string
	{
		return $this->slug;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function rename(string $name): void
	{
		$this->name = $name;
		$this->updatedAt = new \DateTimeImmutable();
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}

	public function getUpdatedAt(): \DateTimeImmutable
	{
		return $this->updatedAt;
	}
}
