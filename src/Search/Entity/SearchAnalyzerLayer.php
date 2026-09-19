<?php

namespace iikiti\CMS\Search\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Search\Enum\SearchLayerType;
use iikiti\CMS\Search\Repository\SearchAnalyzerLayerRepository;

/**
 * An analyzer layer within a search index.
 *
 * Layers form an ordered chain inspired by the Apache Lucene / Elasticsearch
 * analyzer model. A named set of layers (same `name` value) constitutes an
 * analyzer. Layers can be attached to an index (index-wide) or to a specific
 * field (field-level override).
 */
#[ORM\Entity(repositoryClass: SearchAnalyzerLayerRepository::class)]
#[ORM\Table(name: 'search_analyzer_layers')]
#[ORM\Index(name: 'idx_layer_analyzer_pos', columns: ['name', 'position'])]
class SearchAnalyzerLayer
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id = null;

	#[ORM\Column(type: Types::STRING, length: 128)]
	private string $name;

	#[ORM\Column(name: 'search_index_id', type: Types::BIGINT, options: ['unsigned' => true], nullable: true)]
	private int|string|null $searchIndexId = null;

	#[ORM\Column(name: 'field_id', type: Types::BIGINT, options: ['unsigned' => true], nullable: true)]
	private int|string|null $fieldId = null;

	#[ORM\Column(type: Types::STRING, length: 32, options: ['default' => 'tokenfilter'])]
	private string $type;

	#[ORM\Column(type: Types::INTEGER)]
	private int $position = 0;

	/** @var array<string,mixed> */
	#[ORM\Column(type: Types::JSON)]
	private array $options = [];

	public function __construct(
		string $name,
		SearchLayerType $type = SearchLayerType::TokenFilter,
	) {
		$this->name = $name;
		$this->type = $type->value;
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

	public function getType(): SearchLayerType
	{
		return SearchLayerType::from($this->type);
	}

	public function setType(SearchLayerType $type): static
	{
		$this->type = $type->value;

		return $this;
	}

	public function getSearchIndexId(): int|string|null
	{
		return $this->searchIndexId;
	}

	public function setSearchIndexId(int|string|null $searchIndexId): static
	{
		$this->searchIndexId = $searchIndexId;

		return $this;
	}

	public function getFieldId(): int|string|null
	{
		return $this->fieldId;
	}

	public function setFieldId(int|string|null $fieldId): static
	{
		$this->fieldId = $fieldId;

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
		$this->validateOptions($options);
		$this->options = $options;

		return $this;
	}

	public function getOption(string $key, mixed $default = null): mixed
	{
		return $this->options[$key] ?? $default;
	}

	/**
	 * Validate that options match the expected keys for this layer type.
	 *
	 * @param array<string,mixed> $options
	 */
	private function validateOptions(array $options): void
	{
		$expectedKeys = $this->getType()->getOptionKeys();
		if ([] === $expectedKeys) {
			return;
		}

		foreach (array_keys($options) as $key) {
			if (!in_array($key, $expectedKeys, true)) {
				throw new \InvalidArgumentException(sprintf('Option "%s" is not valid for layer type "%s". Expected one of: %s.', $key, $this->getType()->value, implode(', ', $expectedKeys)));
			}
		}
	}
}
