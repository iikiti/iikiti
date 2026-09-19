<?php

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\AuditLogEntryRepository;

/**
 * Audit log entry entity.
 *
 * Records all administrative and system actions for audit purposes. Each entry
 * captures who performed the action, what was done, what was affected, and
 * additional context. In debug mode, extended context is captured.
 */
#[ORM\Entity(repositoryClass: AuditLogEntryRepository::class)]
#[ORM\Table(name: 'audit_log_entries')]
#[ORM\Index(name: 'idx_audit_object', columns: ['object_type', 'object_id'])]
#[ORM\Index(name: 'idx_audit_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_audit_created', columns: ['created_at'])]
class AuditLogEntry
{
	/** @var list<string> */
	public const ACTIONS = [
		'created',
		'updated',
		'deleted',
		'assigned_role',
		'removed_role',
		'joined_group',
		'left_group',
		'enabled_plugin',
		'disabled_plugin',
		'installed_plugin',
		'removed_plugin',
		'updated_configuration',
		'audit_purged',
	];

	/** @var list<string> */
	public const ACTOR_TYPES = ['user', 'system'];

	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
	private ?User $user = null;

	#[ORM\Column(name: 'user_id', type: Types::BIGINT, options: ['unsigned' => true], nullable: true)]
	private int|string|null $userId = null;

	#[ORM\Column(name: 'actor_type', type: Types::STRING, length: 32)]
	private string $actorType = 'user';

	#[ORM\Column(type: Types::STRING, length: 32)]
	private string $action;

	#[ORM\Column(name: 'object_type', type: Types::STRING, length: 128)]
	private string $objectType;

	#[ORM\Column(name: 'object_id', type: Types::BIGINT, options: ['unsigned' => true], nullable: true)]
	private int|string|null $objectId = null;

	/** @var array<string,mixed>|null */
	#[ORM\Column(name: 'before_state', type: Types::JSON, nullable: true)]
	private ?array $beforeState = null;

	/** @var array<string,mixed>|null */
	#[ORM\Column(name: 'after_state', type: Types::JSON, nullable: true)]
	private ?array $afterState = null;

	/** @var array<string,mixed> */
	#[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
	private array $context = [];

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column(name: 'ip_address', type: Types::STRING, length: 45, nullable: true)]
	private ?string $ipAddress = null;

	#[ORM\Column(name: 'user_agent', type: Types::TEXT, nullable: true)]
	private ?string $userAgent = null;

	#[ORM\Column(name: 'request_uri', type: Types::TEXT, nullable: true)]
	private ?string $requestUri = null;

	public function __construct()
	{
		$this->createdAt = new \DateTimeImmutable();
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getUser(): ?User
	{
		return $this->user;
	}

	public function setUser(?User $user): static
	{
		$this->user = $user;
		$this->userId = $user?->getId();

		return $this;
	}

	public function getUserId(): int|string|null
	{
		return $this->userId;
	}

	public function getActorType(): string
	{
		return $this->actorType;
	}

	public function setActorType(string $actorType): static
	{
		$this->actorType = $actorType;

		return $this;
	}

	public function getAction(): string
	{
		return $this->action;
	}

	public function setAction(string $action): static
	{
		$this->action = $action;

		return $this;
	}

	public function getObjectType(): string
	{
		return $this->objectType;
	}

	public function setObjectType(string $objectType): static
	{
		$this->objectType = $objectType;

		return $this;
	}

	public function getObjectId(): int|string|null
	{
		return $this->objectId;
	}

	public function setObjectId(int|string|null $objectId): static
	{
		$this->objectId = $objectId;

		return $this;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function getBeforeState(): ?array
	{
		return $this->beforeState;
	}

	/**
	 * @param array<string,mixed>|null $beforeState
	 */
	public function setBeforeState(?array $beforeState): static
	{
		$this->beforeState = $beforeState;

		return $this;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function getAfterState(): ?array
	{
		return $this->afterState;
	}

	/**
	 * @param array<string,mixed>|null $afterState
	 */
	public function setAfterState(?array $afterState): static
	{
		$this->afterState = $afterState;

		return $this;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getContext(): array
	{
		return $this->context;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function setContext(array $context): static
	{
		$this->context = $context;

		return $this;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}

	public function getIpAddress(): ?string
	{
		return $this->ipAddress;
	}

	public function setIpAddress(?string $ipAddress): static
	{
		$this->ipAddress = $ipAddress;

		return $this;
	}

	public function getUserAgent(): ?string
	{
		return $this->userAgent;
	}

	public function setUserAgent(?string $userAgent): static
	{
		$this->userAgent = $userAgent;

		return $this;
	}

	public function getRequestUri(): ?string
	{
		return $this->requestUri;
	}

	public function setRequestUri(?string $requestUri): static
	{
		$this->requestUri = $requestUri;

		return $this;
	}
}
