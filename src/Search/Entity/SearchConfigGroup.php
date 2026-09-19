<?php

namespace iikiti\CMS\Search\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Search\Repository\SearchConfigGroupRepository;

/**
 * A named group of search index configurations.
 *
 * Allows administrators to organise search indexes and assign them to sites
 * or site groups as a unit. System groups (used for the default admin search
 * config) cannot be deleted.
 */
#[ORM\Entity(repositoryClass: SearchConfigGroupRepository::class)]
#[ORM\Table(name: 'search_config_groups')]
#[ORM\UniqueConstraint(name: 'uniq_config_group_name', columns: ['name'])]
class SearchConfigGroup
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

	#[ORM\Column(name: 'site_id', type: Types::BIGINT, options: ['unsigned' => true], nullable: true)]
	private int|string|null $siteId = null;

	/** @var array<int,string|int> */
	#[ORM\Column(name: 'search_index_ids', type: Types::JSON, options: ['default' => '[]'])]
	private array $searchIndexIds = [];

	/**
	 * Sites directly assigned to this group.
	 *
	 * @var array<int,int|string>
	 */
	#[ORM\Column(name: 'site_assignments', type: Types::JSON, options: ['default' => '[]'])]
	private array $siteAssignments = [];

	/**
	 * Site group IDs this config group is assigned to.
	 *
	 * @var array<int,int|string>
	 */
	#[ORM\Column(name: 'site_group_assignments', type: Types::JSON, options: ['default' => '[]'])]
	private array $siteGroupAssignments = [];

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

	public function getSiteId(): int|string|null
	{
		return $this->siteId;
	}

	public function setSiteId(int|string|null $siteId): static
	{
		$this->siteId = $siteId;

		return $this;
	}

	/**
	 * @return array<int,int|string>
	 */
	public function getSearchIndexIds(): array
	{
		return $this->searchIndexIds;
	}

	/**
	 * @param array<int,int|string> $ids
	 */
	public function setSearchIndexIds(array $ids): static
	{
		$this->searchIndexIds = array_values($ids);

		return $this;
	}

	/**
	 * @return array<int,int|string>
	 */
	public function getSiteAssignments(): array
	{
		return $this->siteAssignments;
	}

	/**
	 * @param array<int,int|string> $siteIds
	 */
	public function setSiteAssignments(array $siteIds): static
	{
		$this->siteAssignments = array_values($siteIds);

		return $this;
	}

	/**
	 * @return array<int,int|string>
	 */
	public function getSiteGroupAssignments(): array
	{
		return $this->siteGroupAssignments;
	}

	/**
	 * @param array<int,int|string> $siteGroupIds
	 */
	public function setSiteGroupAssignments(array $siteGroupIds): static
	{
		$this->siteGroupAssignments = array_values($siteGroupIds);

		return $this;
	}
}
