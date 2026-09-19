<?php

namespace iikiti\CMS\Search\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Search\Repository\SiteGroupRepository;

/**
 * A named group of sites.
 *
 * Allows administrators to assign search configurations to multiple sites
 * as a unit (e.g. "English-speaking sites" or "EU sites").
 */
#[ORM\Entity(repositoryClass: SiteGroupRepository::class)]
#[ORM\Table(name: 'search_site_groups')]
#[ORM\UniqueConstraint(name: 'uniq_site_group_name', columns: ['name'])]
class SiteGroup
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id = null;

	#[ORM\Column(type: Types::STRING, length: 128)]
	private string $name;

	#[ORM\Column(type: Types::STRING, length: 255)]
	private string $label;

	#[ORM\Column(type: Types::TEXT, nullable: true)]
	private ?string $description = null;

	#[ORM\Column(name: 'is_system', type: Types::BOOLEAN, options: ['default' => false])]
	private bool $isSystem = false;

	/**
	 * Site IDs that belong to this group.
	 *
	 * @var array<int,int|string>
	 */
	#[ORM\Column(name: 'site_ids', type: Types::JSON, options: ['default' => '[]'])]
	private array $siteIds = [];

	public function __construct(
		string $name,
		string $label,
	) {
		$this->name = $name;
		$this->label = $label;
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function setName(string $name): static
	{
		$this->name = $name;

		return $this;
	}

	public function getLabel(): string
	{
		return $this->label;
	}

	public function setLabel(string $label): static
	{
		$this->label = $label;

		return $this;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function setDescription(?string $description): static
	{
		$this->description = $description;

		return $this;
	}

	public function isSystem(): bool
	{
		return $this->isSystem;
	}

	public function setSystem(bool $isSystem): static
	{
		$this->isSystem = $isSystem;

		return $this;
	}

	/**
	 * @return array<int,int|string>
	 */
	public function getSiteIds(): array
	{
		return $this->siteIds;
	}

	/**
	 * @param array<int,int|string> $siteIds
	 */
	public function setSiteIds(array $siteIds): static
	{
		$this->siteIds = array_values($siteIds);

		return $this;
	}

	public function addSiteId(int|string $siteId): static
	{
		$siteId = (string) $siteId;
		if (!in_array($siteId, array_map('strval', $this->siteIds), true)) {
			$this->siteIds[] = $siteId;
		}

		return $this;
	}

	public function removeSiteId(int|string $siteId): static
	{
		$siteId = (string) $siteId;
		$this->siteIds = array_values(array_filter(
			array_map('strval', $this->siteIds),
			static fn (string $id): bool => $id !== $siteId
		));

		return $this;
	}
}
