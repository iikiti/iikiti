<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Query;

/**
 * Structured, user-built query definition (never raw SQL).
 *
 * Stored as the `query` block's content. Executed only by {@see QueryExecutor}
 * which translates it into a parameterized Doctrine query with a whitelisted
 * column set and a mandatory site filter.
 */
final class QueryDefinition
{
	/** Field name => DQL expression (e.g. 'title' => 'titleProp.value'). */
	public const COLUMN_MAP = [
		'id' => 'o.id',
		'type' => 'o.type',
		'created_date' => 'o.created_date',
		'title' => 'titleProp.value',
		'slug' => 'slugProp.value',
		'tags' => 'tagsProp.value',
		'content' => 'contentProp.value',
	];

	/** @var array<string,mixed> */
	public readonly array $data;

	/**
	 * @param array<string,mixed> $data
	 */
	public function __construct(array $data)
	{
		$this->data = $data;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		return new self($data);
	}

	public function getSource(): string
	{
		$value = $this->data['source'] ?? null;

		return is_string($value) && '' !== $value ? $value : '';
	}

	public function getObjectType(): string
	{
		$value = $this->data['objectType'] ?? null;

		return is_string($value) ? $value : '';
	}

	/**
	 * @return list<array{field:string, op:string, value:mixed}>
	 */
	public function getFilters(): array
	{
		$filters = $this->data['filters'] ?? [];

		if (!is_array($filters)) {
			return [];
		}

		$out = [];
		foreach ($filters as $filter) {
			if (!is_array($filter)) {
				continue;
			}
			$out[] = [
				'field' => (string) ($filter['field'] ?? ''),
				'op' => (string) ($filter['op'] ?? 'eq'),
				'value' => $filter['value'] ?? null,
			];
		}

		return $out;
	}

	/**
	 * @return list<array{field:string, dir:string}>
	 */
	public function getOrderBy(): array
	{
		$order = $this->data['orderBy'] ?? [];
		$out = [];

		if (!is_array($order)) {
			return [];
		}

		foreach ($order as $item) {
			if (!is_array($item)) {
				continue;
			}
			$out[] = [
				'field' => (string) ($item['field'] ?? ''),
				'dir' => 'desc' === strtolower((string) ($item['dir'] ?? 'asc')) ? 'desc' : 'asc',
			];
		}

		return $out;
	}

	public function getLimit(): int
	{
		$limit = $this->data['limit'] ?? 10;

		return $limit > 0 && is_numeric($limit) ? min((int) $limit, 100) : 10;
	}
}
