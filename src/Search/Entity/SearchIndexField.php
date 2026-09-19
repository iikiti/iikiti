<?php

namespace iikiti\CMS\Search\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Search\Enum\SearchFieldSourceType;
use iikiti\CMS\Search\Repository\SearchIndexFieldRepository;

/**
 * A field definition within a search index.
 *
 * Defines how data is sourced for indexing: a database column, an object
 * property, a virtual (computed) expression, or an alias to another field.
 */
#[ORM\Entity(repositoryClass: SearchIndexFieldRepository::class)]
#[ORM\Table(name: 'search_index_fields')]
#[ORM\Index(name: 'idx_field_index_pos', columns: ['search_index_id', 'position'])]
class SearchIndexField
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: SearchIndex::class, inversedBy: 'fields')]
	#[ORM\JoinColumn(name: 'search_index_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
	private ?SearchIndex $searchIndex = null;

	#[ORM\Column(type: Types::STRING, length: 128)]
	private string $name;

	#[ORM\Column(name: 'source_type', type: Types::STRING, length: 16, options: ['default' => 'property'])]
	private string $sourceType;

	/**
	 * For 'column': column name on the objects table.
	 * For 'property': property key name.
	 * For 'virtual': a SQL/expression fragment.
	 * For 'alias': the target field name to alias.
	 */
	#[ORM\Column(type: Types::TEXT)]
	private string $source;

	#[ORM\Column(name: 'data_type', type: Types::STRING, length: 32, options: ['default' => 'text'])]
	private string $dataType = 'text';

	#[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
	private int $weight = 1;

	#[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
	private ?string $language = null;

	#[ORM\Column(name: 'analyzer_name', type: Types::STRING, length: 128, nullable: true)]
	private ?string $analyzerName = null;

	#[ORM\Column(name: 'is_facetable', type: Types::BOOLEAN, options: ['default' => false])]
	private bool $isFacetable = false;

	#[ORM\Column(name: 'is_sortable', type: Types::BOOLEAN, options: ['default' => false])]
	private bool $isSortable = false;

	#[ORM\Column(type: Types::INTEGER)]
	private int $position = 0;

	public function __construct(
		string $name,
		SearchFieldSourceType $sourceType = SearchFieldSourceType::Property,
		string $source = '',
	) {
		$this->name = $name;
		$this->sourceType = $sourceType->value;
		$this->source = $source;
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

	public function getSourceType(): SearchFieldSourceType
	{
		return SearchFieldSourceType::from($this->sourceType);
	}

	public function setSourceType(SearchFieldSourceType $sourceType): static
	{
		$this->sourceType = $sourceType->value;

		return $this;
	}

	public function getSource(): string
	{
		return $this->source;
	}

	public function setSource(string $source): static
	{
		$this->source = $source;

		return $this;
	}

	public function getDataType(): string
	{
		return $this->dataType;
	}

	public function setDataType(string $dataType): static
	{
		$this->dataType = $dataType;

		return $this;
	}

	public function getWeight(): int
	{
		return $this->weight;
	}

	public function setWeight(int $weight): static
	{
		if ($weight < 1 || $weight > 4) {
			throw new \InvalidArgumentException(sprintf('Weight must be between 1 and 4, got %d.', $weight));
		}
		$this->weight = $weight;

		return $this;
	}

	public function getLanguage(): ?string
	{
		return $this->language;
	}

	public function setLanguage(?string $language): static
	{
		$this->language = $language;

		return $this;
	}

	public function getAnalyzerName(): ?string
	{
		return $this->analyzerName;
	}

	public function setAnalyzerName(?string $analyzerName): static
	{
		$this->analyzerName = $analyzerName;

		return $this;
	}

	public function isFacetable(): bool
	{
		return $this->isFacetable;
	}

	public function setFacetable(bool $isFacetable): static
	{
		$this->isFacetable = $isFacetable;

		return $this;
	}

	public function isSortable(): bool
	{
		return $this->isSortable;
	}

	public function setSortable(bool $isSortable): static
	{
		$this->isSortable = $isSortable;

		return $this;
	}

	public function getPosition(): int
	{
		return $this->position;
	}

	public function setPosition(int $position): static
	{
		$this->position = $position;

		return $this;
	}
}
