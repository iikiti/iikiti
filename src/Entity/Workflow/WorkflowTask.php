<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Workflow;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Role;

#[ORM\Entity]
#[ORM\Table(name: 'workflow_tasks')]
#[ORM\Index(name: 'idx_workflow_task_user_state', columns: ['assignee_user_id', 'status'])]
#[ORM\Index(name: 'idx_workflow_task_role_state', columns: ['site_id', 'assignee_role_id', 'status'])]
#[ORM\Index(name: 'idx_workflow_task_instance_place', columns: ['instance_id', 'place_key'])]
class WorkflowTask
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: WorkflowInstance::class)]
	#[ORM\JoinColumn(name: 'instance_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
	private WorkflowInstance $instance;

	#[ORM\ManyToOne(targetEntity: Site::class)]
	#[ORM\JoinColumn(name: 'site_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
	private ?Site $site;

	#[ORM\Column(name: 'place_key', type: Types::STRING, length: 128)]
	private string $placeKey;

	#[ORM\Column(name: 'token_index', type: Types::INTEGER, options: ['default' => 0])]
	private int $tokenIndex;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(name: 'assignee_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
	private ?User $assigneeUser;

	#[ORM\ManyToOne(targetEntity: Role::class)]
	#[ORM\JoinColumn(name: 'assignee_role_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
	private ?Role $assigneeRole;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(name: 'claimed_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
	private ?User $claimedBy;

	#[ORM\Column(type: Types::STRING, length: 16, options: ['default' => 'open'])]
	private string $status = 'open';

	#[ORM\Column(type: Types::STRING, length: 128)]
	private string $label;

	#[ORM\Version]
	#[ORM\Column(name: 'lock_version', type: Types::INTEGER, options: ['default' => 1])]
	private int $lockVersion = 1;

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $updatedAt;

	#[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
	private ?\DateTimeImmutable $completedAt = null;

	public function __construct(
		WorkflowInstance $instance,
		string $placeKey,
		int $tokenIndex,
		string $label,
		?Site $site = null,
		?User $assigneeUser = null,
		?Role $assigneeRole = null,
	) {
		if (!preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $placeKey) || $tokenIndex < 0) {
			throw new \InvalidArgumentException('Invalid workflow task place or token index.');
		}
		if ((null === $assigneeUser) === (null === $assigneeRole)) {
			throw new \InvalidArgumentException('Workflow task must target exactly one user or site role.');
		}
		if (null !== $assigneeRole && (null === $site || !preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', $assigneeRole->getValue()))) {
			throw new \InvalidArgumentException('Role-assigned workflow tasks require a site and a valid role key.');
		}
		$label = trim($label);
		if ('' === $label || mb_strlen($label) > 128) {
			throw new \InvalidArgumentException('Workflow task label must contain 1–128 characters.');
		}

		$this->instance = $instance;
		$this->site = $site;
		$this->placeKey = $placeKey;
		$this->tokenIndex = $tokenIndex;
		$this->assigneeUser = $assigneeUser;
		$this->assigneeRole = $assigneeRole;
		$this->claimedBy = null;
		$this->label = $label;
		$this->createdAt = new \DateTimeImmutable();
		$this->updatedAt = $this->createdAt;
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getInstance(): WorkflowInstance
	{
		return $this->instance;
	}

	public function getSite(): ?Site
	{
		return $this->site;
	}

	public function getPlaceKey(): string
	{
		return $this->placeKey;
	}

	public function getTokenIndex(): int
	{
		return $this->tokenIndex;
	}

	public function getAssigneeUser(): ?User
	{
		return $this->assigneeUser;
	}

	public function getAssigneeRole(): ?Role
	{
		return $this->assigneeRole;
	}

	public function getAssigneeRoleKey(): ?string
	{
		return $this->assigneeRole?->getValue();
	}

	public function getClaimedBy(): ?User
	{
		return $this->claimedBy;
	}

	public function getStatus(): string
	{
		return $this->status;
	}

	public function getLabel(): string
	{
		return $this->label;
	}

	public function getLockVersion(): int
	{
		return $this->lockVersion;
	}

	public function claim(User $user): void
	{
		if ('open' !== $this->status || null === $this->assigneeRole) {
			throw new \LogicException('Only an open role-assigned workflow task can be claimed.');
		}
		$this->claimedBy = $user;
		$this->status = 'claimed';
		$this->touch();
	}

	public function complete(): void
	{
		if (!in_array($this->status, ['open', 'claimed'], true)) {
			throw new \LogicException('Only an open or claimed workflow task can be completed.');
		}
		$this->status = 'completed';
		$this->completedAt = new \DateTimeImmutable();
		$this->touch();
	}

	public function unclaim(User $user): void
	{
		if ('claimed' !== $this->status || $this->claimedBy?->getId() !== $user->getId()) {
			throw new \LogicException('Only the claimant can release a claimed workflow task.');
		}
		$this->claimedBy = null;
		$this->status = 'open';
		$this->touch();
	}

	private function touch(): void
	{
		$this->updatedAt = new \DateTimeImmutable();
	}
}
