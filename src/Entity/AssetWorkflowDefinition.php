<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

/**
 * Non-persisted processor context retaining the legacy plugin-facing type:
 * asset workflow definitions themselves are versioned rows in the generic
 * workflow tables, while DigitalAssetProcessorInterface implementations keep
 * receiving this immutable object per run.
 */
final readonly class AssetWorkflowDefinition
{
	/** @param array<string,mixed> $conditions
	 * @param array<string,mixed> $options
	 */
	public function __construct(
		public string $key,
		public string $name,
		public string $processorKey,
		public string $mimePattern,
		public array $conditions = [],
		public array $options = [],
	) {
		if (!preg_match('/^[a-z][a-z0-9_.-]{1,127}$/D', $this->key)) {
			throw new \InvalidArgumentException('Workflow key must be a lowercase identifier.');
		}
		if (!preg_match('/^[a-z][a-z0-9_.-]{1,127}$/D', $this->processorKey)) {
			throw new \InvalidArgumentException('Invalid processor key.');
		}
		if (!preg_match('~^[a-z0-9.+-]+/(?:[a-z0-9.+-]+|\*)$~D', strtolower(trim($this->mimePattern)))) {
			throw new \InvalidArgumentException('Invalid MIME pattern.');
		}
	}

	public function getKey(): string
	{
		return $this->key;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getProcessorKey(): string
	{
		return $this->processorKey;
	}

	public function getMimePattern(): string
	{
		return $this->mimePattern;
	}

	/** @return array<string,mixed> */
	public function getConditions(): array
	{
		return $this->conditions;
	}

	/** @return array<string,mixed> */
	public function getOptions(): array
	{
		return $this->options;
	}

	public function matches(string $mimeType): bool
	{
		$pattern = preg_quote($this->mimePattern, '~');
		$pattern = str_replace('\\*', '[^/]+', $pattern);

		return 1 === preg_match('~^'.$pattern.'$~i', $mimeType);
	}
}
