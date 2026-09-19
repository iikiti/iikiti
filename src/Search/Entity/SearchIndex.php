<?php

namespace iikiti\CMS\Search\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Search\Enum\SearchIndexType;
use iikiti\CMS\Search\Exception\SystemLockedException;
use iikiti\CMS\Search\Repository\SearchIndexRepository;

/**
 * Search index configuration.
 *
 * Defines which object types/properties are indexed, which search engine
 * adapter to use, and how the index maps to sites and configuration groups.
 */
#[ORM\Entity(repositoryClass: SearchIndexRepository::class)]
#[ORM\Table(name: 'search_indexes')]
#[ORM\UniqueConstraint(name: 'uniq_search_index_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_search_index_slug_site', columns: ['slug', 'site_id'])]
class SearchIndex
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id = null;

	#[ORM\Column(type: Types::STRING, length: 128)]
	private string $slug;

	#[ORM\Column(type: Types::STRING, length: 255)]
	private string $name;

	#[ORM\Column(type: Types::STRING, length: 16, options: ['default' => 'custom'])]
	private string $type;

	#[ORM\Column(type: Types::STRING, length: 64, options: ['default' => 'postgresql'])]
	private string $engine = 'postgresql';

	#[ORM\Column(type: Types::STRING, length: 32, options: ['default' => 'english'])]
	private string $language = 'english';

	#[ORM\Column(type: Types::TEXT, nullable: true)]
	private ?string $description = null;

	#[ORM\Column(name: 'system_locked', type: Types::BOOLEAN, options: ['default' => false])]
	private bool $systemLocked = false;

	#[ORM\Column(name: 'is_enabled', type: Types::BOOLEAN, options: ['default' => true])]
	private bool $isEnabled = true;

	#[ORM\Column(name: 'site_id', type: Types::BIGINT, options: ['unsigned' => true], nullable: true)]
	private int|string|null $siteId = null;

	#[ORM\Column(name: 'config_group_id', type: Types::BIGINT, options: ['unsigned' => true], nullable: true)]
	private int|string|null $configGroupId = null;

	/** @var array<string,mixed> */
	#[ORM\Column(type: Types::JSON)]
	private array $options = [];

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $updatedAt;

	/** @var Collection<int,SearchIndexField> */
	#[ORM\OneToMany(targetEntity: SearchIndexField::class, mappedBy: 'searchIndex', cascade: ['persist', 'remove'], orphanRemoval: true)]
	private Collection $fields;

	/** @var Collection<int,SearchFilter> */
	#[ORM\OneToMany(targetEntity: SearchFilter::class, mappedBy: 'searchIndex', cascade: ['persist', 'remove'], orphanRemoval: true)]
	private Collection $filters;

	public function __construct(
		string $slug,
		string $name,
		SearchIndexType $type = SearchIndexType::Custom,
		?string $engine = null,
		?string $language = null,
	) {
		$this->slug = $slug;
		$this->name = $name;
		$this->type = $type->value;
		$this->engine = $engine ?? 'postgresql';
		$this->language = $language ?? 'english';
		$this->createdAt = new \DateTimeImmutable();
		$this->updatedAt = new \DateTimeImmutable();
		$this->fields = new ArrayCollection();
		$this->filters = new ArrayCollection();
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getSlug(): string
	{
		return $this->slug;
	}

	public function setSlug(string $slug): static
	{
		$this->slug = $slug;

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

	public function getType(): SearchIndexType
	{
		return SearchIndexType::from($this->type);
	}

	public function setType(SearchIndexType $type): static
	{
		$this->type = $type->value;
		$this->touch();

		return $this;
	}

	public function getEngine(): string
	{
		return $this->engine;
	}

	public function setEngine(string $engine): static
	{
		if ($this->isSystemLocked()) {
			throw SystemLockedException::cannotChangeEngine($this->slug);
		}
		$this->engine = $engine;
		$this->touch();

		return $this;
	}

	public function getLanguage(): string
	{
		return $this->language;
	}

	public function setLanguage(string $language): static
	{
		$this->language = $language;
		$this->touch();

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

	public function isSystemLocked(): bool
	{
		return $this->systemLocked;
	}

	public function setSystemLocked(bool $systemLocked): static
	{
		$this->systemLocked = $systemLocked;

		return $this;
	}

	public function isEnabled(): bool
	{
		return $this->isEnabled;
	}

	public function setEnabled(bool $isEnabled): static
	{
		$this->isEnabled = $isEnabled;
		$this->touch();

		return $this;
	}

	public function getSiteId(): int|string|null
	{
		return $this->siteId;
	}

	public function setSiteId(int|string|null $siteId): static
	{
		$this->siteId = $siteId;
		$this->touch();

		return $this;
	}

	public function getConfigGroupId(): int|string|null
	{
		return $this->configGroupId;
	}

	public function setConfigGroupId(int|string|null $configGroupId): static
	{
		$this->configGroupId = $configGroupId;
		$this->touch();

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
		$this->touch();

		return $this;
	}

	public function getOption(string $key, mixed $default = null): mixed
	{
		return $this->options[$key] ?? $default;
	}

	/**
	 * @return Collection<int,SearchIndexField>
	 */
	public function getFields(): Collection
	{
		return $this->fields;
	}

	/**
	 * @return Collection<int,SearchFilter>
	 */
	public function getFilters(): Collection
	{
		return $this->filters;
	}

	public function addField(SearchIndexField $field): static
	{
		if (!$this->fields->contains($field)) {
			$this->fields->add($field);
			$field->setSearchIndex($this);
		}

		return $this;
	}

	public function removeField(SearchIndexField $field): static
	{
		if ($this->fields->removeElement($field)) {
			if ($field->getSearchIndex() === $this) {
				$field->setSearchIndex(null);
			}
		}

		return $this;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}

	public function getUpdatedAt(): \DateTimeImmutable
	{
		return $this->updatedAt;
	}

	public function touch(): void
	{
		$this->updatedAt = new \DateTimeImmutable();
	}
}
