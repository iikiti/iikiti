<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\AssetWorkflowDefinitionRepository;

#[ORM\Entity(repositoryClass: AssetWorkflowDefinitionRepository::class)]
#[ORM\Table(name: 'digital_asset_workflows')]
#[ORM\UniqueConstraint(name: 'uniq_digital_asset_workflow_key', columns: ['workflow_key'])]
class AssetWorkflowDefinition
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\Column(name: 'workflow_key', type: Types::STRING, length: 64)]
	private string $key;

	#[ORM\Column(type: Types::STRING, length: 128)]
	private string $name;

	#[ORM\Column(name: 'processor_key', type: Types::STRING, length: 64)]
	private string $processorKey;

	#[ORM\Column(name: 'mime_pattern', type: Types::STRING, length: 127)]
	private string $mimePattern;

	/** @var array<string,mixed> */
	#[ORM\Column(type: Types::JSON)]
	private array $conditions;

	/** @var array<string,mixed> */
	#[ORM\Column(type: Types::JSON)]
	private array $options;

	#[ORM\Column(name: 'is_enabled', type: Types::BOOLEAN, options: ['default' => true])]
	private bool $enabled = true;

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $updatedAt;

	/**
	 * @param array<string,mixed> $conditions
	 * @param array<string,mixed> $options
	 */
	public function __construct(string $key, string $name, string $processorKey, string $mimePattern, array $conditions = [], array $options = [])
	{
		$this->setKey($key);
		$this->setName($name);
		$this->setProcessorKey($processorKey);
		$this->setMimePattern($mimePattern);
		$this->conditions = $conditions;
		$this->options = $options;
		$this->createdAt = new \DateTimeImmutable();
		$this->updatedAt = $this->createdAt;
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getKey(): string
	{
		return $this->key;
	}

	public function setKey(string $key): static
	{
		if (!preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $key)) {
			throw new \InvalidArgumentException('Workflow key must be a lowercase identifier.');
		}
		$this->key = $key;
		$this->touch();

		return $this;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function setName(string $name): static
	{
		$name = trim($name);
		if ('' === $name || mb_strlen($name) > 128) {
			throw new \InvalidArgumentException('Workflow name must contain 1–128 characters.');
		}
		$this->name = $name;
		$this->touch();

		return $this;
	}

	public function getProcessorKey(): string
	{
		return $this->processorKey;
	}

	public function setProcessorKey(string $processorKey): static
	{
		if (!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $processorKey)) {
			throw new \InvalidArgumentException('Invalid processor key.');
		}
		$this->processorKey = $processorKey;
		$this->touch();

		return $this;
	}

	public function getMimePattern(): string
	{
		return $this->mimePattern;
	}

	public function setMimePattern(string $mimePattern): static
	{
		$mimePattern = strtolower(trim($mimePattern));
		if (!preg_match('~^[a-z0-9.+-]+/(?:[a-z0-9.+-]+|\*)$~', $mimePattern)) {
			throw new \InvalidArgumentException('Invalid MIME pattern.');
		}
		$this->mimePattern = $mimePattern;
		$this->touch();

		return $this;
	}

	/** @return array<string,mixed> */
	public function getConditions(): array
	{
		return $this->conditions;
	}

	/** @param array<string,mixed> $conditions */
	public function setConditions(array $conditions): static
	{
		$this->conditions = $conditions;
		$this->touch();

		return $this;
	}

	/** @return array<string,mixed> */
	public function getOptions(): array
	{
		return $this->options;
	}

	/** @param array<string,mixed> $options */
	public function setOptions(array $options): static
	{
		$this->options = $options;
		$this->touch();

		return $this;
	}

	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	public function setEnabled(bool $enabled): static
	{
		$this->enabled = $enabled;
		$this->touch();

		return $this;
	}

	public function matches(string $mimeType): bool
	{
		$pattern = preg_quote($this->mimePattern, '~');
		$pattern = str_replace('\\*', '[^/]+', $pattern);

		return 1 === preg_match('~^'.$pattern.'$~i', $mimeType);
	}

	private function touch(): void
	{
		$this->updatedAt = new \DateTimeImmutable();
	}
}
