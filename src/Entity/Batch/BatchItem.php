<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Batch;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'batch_items')]
#[ORM\UniqueConstraint(name: 'uniq_batch_item_job_key', columns: ['batch_job_id', 'item_key'])]
#[ORM\UniqueConstraint(name: 'uniq_batch_item_idempotency', columns: ['batch_job_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_batch_item_job_state', columns: ['batch_job_id', 'state'])]
#[ORM\Index(name: 'idx_batch_item_state_locked', columns: ['state', 'locked_at'])]
class BatchItem
{
	public const STATE_QUEUED = 'queued';
	public const STATE_WAITING = 'waiting';
	public const STATE_RUNNING = 'running';
	public const STATE_RETRYING = 'retrying';
	public const STATE_SUCCEEDED = 'succeeded';
	public const STATE_FAILED = 'failed';
	public const STATE_SKIPPED = 'skipped';

	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: BatchJob::class)]
	#[ORM\JoinColumn(name: 'batch_job_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
	private BatchJob $job;

	#[ORM\Column(name: 'item_key', type: Types::STRING, length: 128)]
	private string $key;

	#[ORM\Column(name: 'type_key', type: Types::STRING, length: 128)]
	private string $type;

	#[ORM\Column(type: Types::SMALLINT)]
	private int $version;

	/** @var array<string,mixed> */
	#[ORM\Column(type: Types::JSON)]
	private array $payload;

	#[ORM\Column(type: Types::STRING, length: 255)]
	private string $summary;

	#[ORM\Column(name: 'idempotency_key', type: Types::STRING, length: 128)]
	private string $idempotencyKey;

	#[ORM\Column(type: Types::STRING, length: 16)]
	private string $state = self::STATE_QUEUED;

	#[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
	private int $attempts = 0;

	#[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
	private ?string $lastError = null;

	#[ORM\Column(name: 'locked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
	private ?\DateTimeImmutable $lockedAt = null;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $updatedAt;

	/** @param array<string,mixed> $payload */
	public function __construct(BatchJob $job, string $key, string $type, int $version, array $payload, string $summary, string $idempotencyKey, bool $waiting = false)
	{
		$this->job = $job;
		$this->key = $key;
		$this->type = $type;
		$this->version = $version;
		$this->payload = $payload;
		$this->summary = $summary;
		$this->idempotencyKey = $idempotencyKey;
		$this->state = $waiting ? self::STATE_WAITING : self::STATE_QUEUED;
		$this->updatedAt = new \DateTimeImmutable();
	}

	public function getId(): int|string|null { return $this->id; }
	public function getJob(): BatchJob { return $this->job; }
	public function getKey(): string { return $this->key; }
	public function getType(): string { return $this->type; }
	public function getVersion(): int { return $this->version; }
	/** @return array<string,mixed> */ public function getPayload(): array { return $this->payload; }
	public function getSummary(): string { return $this->summary; }
	public function getIdempotencyKey(): string { return $this->idempotencyKey; }
	public function getState(): string { return $this->state; }
	public function getAttempts(): int { return $this->attempts; }
	public function getLastError(): ?string { return $this->lastError; }
	public function getLockedAt(): ?\DateTimeImmutable { return $this->lockedAt; }

	public function setState(string $state, ?string $error = null): void
	{
		if (!in_array($state, [self::STATE_QUEUED, self::STATE_WAITING, self::STATE_RUNNING, self::STATE_RETRYING, self::STATE_SUCCEEDED, self::STATE_FAILED, self::STATE_SKIPPED], true)) {
			throw new \InvalidArgumentException('Invalid batch item state.');
		}
		$this->state = $state;
		$this->lastError = null === $error ? null : mb_substr($error, 0, 4000);
		$this->lockedAt = self::STATE_RUNNING === $state ? new \DateTimeImmutable() : null;
		$this->updatedAt = new \DateTimeImmutable();
	}

	public function beginAttempt(): void
	{
		++$this->attempts;
		$this->setState(self::STATE_RUNNING);
	}
}
