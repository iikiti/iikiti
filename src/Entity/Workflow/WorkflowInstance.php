<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Workflow;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Workflow\WorkflowInstanceRepository;

#[ORM\Entity(repositoryClass: WorkflowInstanceRepository::class)]
#[ORM\Table(name: 'workflow_instances')]
#[ORM\Index(name: 'idx_workflow_instance_subject', columns: ['subject_type', 'subject_id'])]
#[ORM\Index(name: 'idx_workflow_instance_site_state', columns: ['site_id', 'execution_state'])]
#[ORM\Index(name: 'idx_workflow_instance_version', columns: ['definition_version_id'])]
#[ORM\UniqueConstraint(name: 'uniq_workflow_instance_idempotency', columns: ['idempotency_key'])]
class WorkflowInstance
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: WorkflowDefinitionVersion::class)]
	#[ORM\JoinColumn(name: 'definition_version_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
	private WorkflowDefinitionVersion $definitionVersion;

	#[ORM\ManyToOne(targetEntity: Site::class)]
	#[ORM\JoinColumn(name: 'site_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
	private ?Site $site;

	#[ORM\Column(name: 'subject_type', type: Types::STRING, length: 64, nullable: true)]
	private ?string $subjectType;

	#[ORM\Column(name: 'subject_id', type: Types::STRING, length: 128, nullable: true)]
	private ?string $subjectId;

	/** @var array<string,int> */
	#[ORM\Column(type: Types::JSON)]
	private array $marking;

	/** @var array<string,mixed> */
	#[ORM\Column(type: Types::JSON)]
	private array $context;

	#[ORM\Column(name: 'execution_state', type: Types::STRING, length: 16, options: ['default' => 'active'])]
	private string $executionState = 'active';

	#[ORM\Column(name: 'pause_reason', type: Types::STRING, length: 512, nullable: true)]
	private ?string $pauseReason = null;

	#[ORM\Column(name: 'pause_type', type: Types::STRING, length: 16, options: ['default' => 'manual'])]
	private string $pauseType = 'manual';

	#[ORM\Column(name: 'idempotency_key', type: Types::STRING, length: 128, nullable: true)]
	private ?string $idempotencyKey;

	#[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
	private int $attempts = 0;

	#[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
	private ?string $lastError = null;

	#[ORM\Version]
	#[ORM\Column(name: 'lock_version', type: Types::INTEGER, options: ['default' => 1])]
	private int $lockVersion = 1;

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $updatedAt;

	private ?User $runtimeActor = null;
	private ?object $runtimeSubject = null;
	private bool $runtimeAutomaticTransition = false;

	/** @param array<string,mixed> $context */
	public function __construct(
		WorkflowDefinitionVersion $definitionVersion,
		?Site $site = null,
		?string $subjectType = null,
		int|string|null $subjectId = null,
		array $context = [],
		?string $idempotencyKey = null,
	) {
		if ((null === $subjectType) !== (null === $subjectId)) {
			throw new \InvalidArgumentException('Workflow subjects require both a registered type key and an ID.');
		}
		if (null !== $subjectType && (!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $subjectType) || mb_strlen((string) $subjectId) > 128)) {
			throw new \InvalidArgumentException('Invalid workflow subject reference.');
		}
		if (null !== $idempotencyKey && ('' === trim($idempotencyKey) || mb_strlen($idempotencyKey) > 128)) {
			throw new \InvalidArgumentException('Invalid workflow idempotency key.');
		}

		$this->definitionVersion = $definitionVersion;
		$this->site = $site;
		$this->subjectType = $subjectType;
		$this->subjectId = null === $subjectId ? null : (string) $subjectId;
		$this->marking = $this->initialMarking($definitionVersion->getGraph());
		$this->context = $context;
		$this->idempotencyKey = $idempotencyKey;
		$this->createdAt = new \DateTimeImmutable();
		$this->updatedAt = $this->createdAt;
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getDefinitionVersion(): WorkflowDefinitionVersion
	{
		return $this->definitionVersion;
	}

	public function getSite(): ?Site
	{
		return $this->site;
	}

	public function getSubjectType(): ?string
	{
		return $this->subjectType;
	}

	public function getSubjectId(): ?string
	{
		return $this->subjectId;
	}

	/** @return array<string,int> */
	public function getMarking(): array
	{
		return $this->marking;
	}

	/**
	 * @param array<string,int>   $marking
	 * @param array<string,mixed> $context
	 */
	public function setMarking(array $marking, array $context = []): void
	{
		$this->marking = $marking;
		$this->touch();
	}

	/** @return array<string,mixed> */
	public function getContext(): array
	{
		return $this->context;
	}

	/** @param array<string,mixed> $context */
	public function setContext(array $context): void
	{
		$this->context = $context;
		$this->touch();
	}

	public function getExecutionState(): string
	{
		return $this->executionState;
	}

	public function isPaused(): bool
	{
		return 'paused' === $this->executionState;
	}

	public function getPauseReason(): ?string
	{
		return $this->pauseReason;
	}

	public function getPauseType(): string
	{
		return $this->pauseType;
	}

	public function pause(string $reason, string $type = 'manual'): void
	{
		$reason = trim($reason);
		if ('' === $reason || mb_strlen($reason) > 512) {
			throw new \InvalidArgumentException('A workflow pause requires a reason of at most 512 characters.');
		}
		if (!in_array($type, ['manual', 'plugin', 'system'], true)) {
			throw new \InvalidArgumentException('Invalid workflow pause type.');
		}
		$this->executionState = 'paused';
		$this->pauseReason = $reason;
		$this->pauseType = $type;
		$this->touch();
	}

	public function resume(): void
	{
		if ('paused' !== $this->executionState) {
			return;
		}
		$this->executionState = 'active';
		$this->pauseReason = null;
		$this->pauseType = 'manual';
		$this->touch();
	}

	public function getIdempotencyKey(): ?string
	{
		return $this->idempotencyKey;
	}

	public function getAttempts(): int
	{
		return $this->attempts;
	}

	public function recordAttempt(): void
	{
		++$this->attempts;
		$this->touch();
	}

	public function getLastError(): ?string
	{
		return $this->lastError;
	}

	public function setLastError(?string $error): void
	{
		$this->lastError = $error;
		$this->touch();
	}

	public function getLockVersion(): int
	{
		return $this->lockVersion;
	}

	public function setRuntimeContext(?User $actor, ?object $subject, bool $automaticTransition = false): void
	{
		$this->runtimeActor = $actor;
		$this->runtimeSubject = $subject;
		$this->runtimeAutomaticTransition = $automaticTransition;
	}

	public function getRuntimeActor(): ?User
	{
		return $this->runtimeActor;
	}

	public function getRuntimeSubject(): ?object
	{
		return $this->runtimeSubject;
	}

	public function isRuntimeAutomaticTransition(): bool
	{
		return $this->runtimeAutomaticTransition;
	}

	/**
	 * @param array<string,mixed> $graph
	 *
	 * @return array<string,int>
	 */
	private function initialMarking(array $graph): array
	{
		$initialPlaces = $graph['initialPlaces'] ?? null;
		if (!is_array($initialPlaces) || [] === $initialPlaces) {
			throw new \InvalidArgumentException('Workflow definition graph requires non-empty initialPlaces.');
		}

		$marking = [];
		foreach ($initialPlaces as $place) {
			if (!is_string($place) || '' === $place) {
				throw new \InvalidArgumentException('Initial workflow places must be non-empty strings.');
			}
			$marking[$place] = ($marking[$place] ?? 0) + 1;
		}

		return $marking;
	}

	private function touch(): void
	{
		$this->updatedAt = new \DateTimeImmutable();
	}
}
