<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Workflow;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\User;

#[ORM\Entity]
#[ORM\Table(name: 'workflow_definition_versions')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_definition_version', columns: ['definition_id', 'version'])]
class WorkflowDefinitionVersion
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: WorkflowDefinition::class)]
	#[ORM\JoinColumn(name: 'definition_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
	private WorkflowDefinition $definition;

	#[ORM\Column(type: Types::INTEGER)]
	private int $version;

	/** @var array<string,mixed> */
	#[ORM\Column(type: Types::JSON)]
	private array $graph;

	#[ORM\Column(name: 'policy_key', type: Types::STRING, length: 128, nullable: true)]
	private ?string $policyKey;

	#[ORM\Column(name: 'source_hash', type: Types::STRING, length: 64, nullable: true)]
	private ?string $sourceHash;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(name: 'created_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
	private ?User $createdBy;

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	/** @param array<string,mixed> $graph */
	public function __construct(
		WorkflowDefinition $definition,
		int $version,
		array $graph,
		?string $policyKey = null,
		?string $sourceHash = null,
		?User $createdBy = null,
	) {
		if ($version < 1) {
			throw new \InvalidArgumentException('Workflow definition versions start at one.');
		}
		if ([] === $graph) {
			throw new \InvalidArgumentException('A workflow graph cannot be empty.');
		}
		if (null !== $policyKey && !preg_match('/^[a-z][a-z0-9_.-]{1,127}$/D', $policyKey)) {
			throw new \InvalidArgumentException('Invalid workflow policy key.');
		}
		if (null !== $sourceHash && !preg_match('/^[a-f0-9]{64}$/D', $sourceHash)) {
			throw new \InvalidArgumentException('Workflow source hash must be a SHA-256 hex digest.');
		}

		$this->definition = $definition;
		$this->version = $version;
		$this->graph = $graph;
		$this->policyKey = $policyKey;
		$this->sourceHash = $sourceHash;
		$this->createdBy = $createdBy;
		$this->createdAt = new \DateTimeImmutable();
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getDefinition(): WorkflowDefinition
	{
		return $this->definition;
	}

	public function getVersion(): int
	{
		return $this->version;
	}

	/** @return array<string,mixed> */
	public function getGraph(): array
	{
		return $this->graph;
	}

	public function getPolicyKey(): ?string
	{
		return $this->policyKey;
	}

	public function getSourceHash(): ?string
	{
		return $this->sourceHash;
	}

	public function getCreatedBy(): ?User
	{
		return $this->createdBy;
	}
}
