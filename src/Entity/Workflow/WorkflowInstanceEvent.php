<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Workflow;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\User;

#[ORM\Entity]
#[ORM\Table(name: 'workflow_instance_events')]
#[ORM\Index(name: 'idx_workflow_instance_event_history', columns: ['instance_id', 'created_at'])]
class WorkflowInstanceEvent
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: WorkflowInstance::class)]
	#[ORM\JoinColumn(name: 'instance_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
	private WorkflowInstance $instance;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(name: 'actor_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
	private ?User $actor;

	#[ORM\Column(name: 'event_type', type: Types::STRING, length: 32)]
	private string $eventType;

	#[ORM\Column(name: 'transition_name', type: Types::STRING, length: 128, nullable: true)]
	private ?string $transitionName;

	/** @var array<string,int>|null */
	#[ORM\Column(name: 'from_marking', type: Types::JSON, nullable: true)]
	private ?array $fromMarking;

	/** @var array<string,int>|null */
	#[ORM\Column(name: 'to_marking', type: Types::JSON, nullable: true)]
	private ?array $toMarking;

	/** @var array<string,mixed> */
	#[ORM\Column(type: Types::JSON)]
	private array $details;

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	/**
	 * @param array<string,int>|null $fromMarking
	 * @param array<string,int>|null $toMarking
	 * @param array<string,mixed>    $details
	 */
	public function __construct(
		WorkflowInstance $instance,
		string $eventType,
		?User $actor = null,
		?string $transitionName = null,
		?array $fromMarking = null,
		?array $toMarking = null,
		array $details = [],
	) {
		if (!preg_match('/^[a-z][a-z0-9_.-]{1,31}$/D', $eventType)) {
			throw new \InvalidArgumentException('Invalid workflow instance event type.');
		}
		if (null !== $transitionName && !preg_match('/^[a-z][a-z0-9_.-]{1,127}$/D', $transitionName)) {
			throw new \InvalidArgumentException('Invalid workflow transition name.');
		}

		$this->instance = $instance;
		$this->eventType = $eventType;
		$this->actor = $actor;
		$this->transitionName = $transitionName;
		$this->fromMarking = $fromMarking;
		$this->toMarking = $toMarking;
		$this->details = $details;
		$this->createdAt = new \DateTimeImmutable();
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getInstance(): WorkflowInstance
	{
		return $this->instance;
	}

	public function getActor(): ?User
	{
		return $this->actor;
	}

	public function getEventType(): string
	{
		return $this->eventType;
	}

	public function getTransitionName(): ?string
	{
		return $this->transitionName;
	}

	/** @return array<string,int>|null */
	public function getFromMarking(): ?array
	{
		return $this->fromMarking;
	}

	/** @return array<string,int>|null */
	public function getToMarking(): ?array
	{
		return $this->toMarking;
	}

	/** @return array<string,mixed> */
	public function getDetails(): array
	{
		return $this->details;
	}
}
