<?php

namespace iikiti\CMS\Search\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Search\Enum\SearchFilterMode;
use iikiti\CMS\Search\Enum\SearchFilterVisibility;
use iikiti\CMS\Search\Repository\SearchFilterRepository;

/**
 * A custom search filter.
 *
 * Filters can be query-time (modify the search query) or index-time (modify
 * data before indexing). They can be public (visible on the front-end),
 * admin-only, or restricted to users with specific roles.
 */
#[ORM\Entity(repositoryClass: SearchFilterRepository::class)]
#[ORM\Table(name: 'search_filters')]
class SearchFilter
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: SearchIndex::class, inversedBy: 'filters')]
	#[ORM\JoinColumn(name: 'search_index_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
	private ?SearchIndex $searchIndex = null;

	#[ORM\Column(type: Types::STRING, length: 128)]
	private string $name;

	#[ORM\Column(type: Types::STRING, length: 255)]
	private string $label;

	#[ORM\Column(type: Types::STRING, length: 32, options: ['default' => 'query_time'])]
	private string $mode;

	#[ORM\Column(type: Types::STRING, length: 16, options: ['default' => 'public_frontend'])]
	private string $visibility = 'public_frontend';

	/** @var array<string>|null */
	#[ORM\Column(name: 'required_roles', type: Types::JSON, nullable: true)]
	private ?array $requiredRoles = null;

	/**
	 * Service ID + "::method" for programmatic invocation, e.g.
	 * "App\Search\DateRangeFilter::apply". Null means the filter is
	 * declarative (options only) and resolved by the search engine.
	 */
	#[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
	private ?string $hook = null;

	/** @var array<string,mixed> */
	#[ORM\Column(type: Types::JSON)]
	private array $options = [];

	#[ORM\Column(name: 'is_enabled', type: Types::BOOLEAN, options: ['default' => true])]
	private bool $isEnabled = true;

	public function __construct(
		string $name,
		string $label,
		SearchFilterMode $mode = SearchFilterMode::QueryTime,
		SearchFilterVisibility $visibility = SearchFilterVisibility::PublicFrontend,
	) {
		$this->name = $name;
		$this->label = $label;
		$this->mode = $mode->value;
		$this->visibility = $visibility->value;
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getSearchIndex(): ?SearchIndex
	{
		return $this->searchIndex;
	}

	public function setSearchIndex(?SearchIndex $searchIndex): static
	{
		$this->searchIndex = $searchIndex;

		return $this;
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

	public function getMode(): SearchFilterMode
	{
		return SearchFilterMode::from($this->mode);
	}

	public function setMode(SearchFilterMode $mode): static
	{
		$this->mode = $mode->value;

		return $this;
	}

	public function getVisibility(): SearchFilterVisibility
	{
		return SearchFilterVisibility::from($this->visibility);
	}

	public function setVisibility(SearchFilterVisibility $visibility): static
	{
		$this->visibility = $visibility->value;

		return $this;
	}

	/**
	 * @return list<string>
	 */
	public function getRequiredRoles(): array
	{
		return $this->requiredRoles ?? [];
	}

	/**
	 * @param list<string> $roles
	 */
	public function setRequiredRoles(array $roles): static
	{
		$this->requiredRoles = $roles;

		return $this;
	}

	public function getHook(): ?string
	{
		return $this->hook;
	}

	public function setHook(?string $hook): static
	{
		$this->hook = $hook;

		return $this;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getOptions(): array
	{
		return $this->options;
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function setOptions(array $options): static
	{
		$this->options = $options;

		return $this;
	}

	public function getOption(string $key, mixed $default = null): mixed
	{
		return $this->options[$key] ?? $default;
	}

	public function isEnabled(): bool
	{
		return $this->isEnabled;
	}

	public function setEnabled(bool $isEnabled): static
	{
		$this->isEnabled = $isEnabled;

		return $this;
	}

	/**
	 * Whether this filter is visible to the front-end search.
	 */
	public function isVisibleToFrontend(): bool
	{
		return $this->getVisibility()->isVisibleToFrontend();
	}
}
