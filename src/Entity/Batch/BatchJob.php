<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Batch;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'batch_jobs')]
#[ORM\Index(name: 'idx_batch_job_site_status', columns: ['site_id', 'status'])]
#[ORM\Index(name: 'idx_batch_job_creator_status', columns: ['created_by_user_id', 'status'])]
class BatchJob
{
	public const STATUS_QUEUED = 'queued';
	public const STATUS_RUNNING = 'running';
	public const STATUS_PAUSED = 'paused';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
	public const STATUS_FAILED = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\Column(type: Types::STRING, length: 255)]
	private string $name;

	#[ORM\Column(name: 'task_key', type: Types::STRING, length: 128, nullable: true)]
	private ?string $taskKey;

	#[ORM\Column(type: Types::STRING, length: 32)]
	private string $status = self::STATUS_QUEUED;

	#[ORM\Column(name: 'site_id', type: Types::BIGINT, nullable: true)]
	private int|string|null $siteId;

	#[ORM\Column(name: 'created_by_user_id', type: Types::BIGINT, nullable: true)]
	private int|string|null $createdByUserId;

	#[ORM\Column(name: 'max_attempts', type: Types::SMALLINT, options: ['default' => 3])]
	private int $maxAttempts;

	#[ORM\Column(name: 'total_items', type: Types::BIGINT, options: ['default' => 0])]
	private int $totalItems = 0;

	#[ORM\Column(name: 'terminal_items', type: Types::BIGINT, options: ['default' => 0])]
	private int $terminalItems = 0;

	#[ORM\Column(name: 'total_known', type: Types::BOOLEAN, options: ['default' => true])]
	private bool $totalKnown;

	/** @var array<string,mixed> */
	#[ORM\Column(type: Types::JSON)]
	private array $configuration;

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $updatedAt;

	#[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
	private ?\DateTimeImmutable $completedAt = null;

	/** @param array<string,mixed> $configuration */
	public function __construct(string $name, ?string $taskKey, int|string|null $siteId, int|string|null $createdByUserId, int $maxAttempts, bool $totalKnown, array $configuration = [])
	{
		$name = trim($name);
		if ('' === $name || mb_strlen($name) > 255) {
			throw new \InvalidArgumentException('Batch name must contain 1–255 characters.');
		}
		if ($maxAttempts < 1 || $maxAttempts > 25) {
			throw new \InvalidArgumentException('Batch maximum attempts must be between 1 and 25.');
		}
		$this->name = $name;
		$this->taskKey = $taskKey;
		$this->siteId = $siteId;
		$this->createdByUserId = $createdByUserId;
		$this->maxAttempts = $maxAttempts;
		$this->totalKnown = $totalKnown;
		$this->configuration = $configuration;
		$this->createdAt = new \DateTimeImmutable();
		$this->updatedAt = $this->createdAt;
	}

	public function getId(): int|string|null { return $this->id; }
	public function getName(): string { return $this->name; }
	public function getTaskKey(): ?string { return $this->taskKey; }
	public function getStatus(): string { return $this->status; }
	public function getSiteId(): int|string|null { return $this->siteId; }
	public function getCreatedByUserId(): int|string|null { return $this->createdByUserId; }
	public function getMaxAttempts(): int { return $this->maxAttempts; }
	public function getTotalItems(): int { return $this->totalItems; }
	public function getTerminalItems(): int { return $this->terminalItems; }
	public function isTotalKnown(): bool { return $this->totalKnown; }
	/** @return array<string,mixed> */ public function getConfiguration(): array { return $this->configuration; }
	public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
	public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
	public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }

	public function setCounts(int $totalItems, int $terminalItems, bool $totalKnown = true): void
	{
		$this->totalItems = max(0, $totalItems);
		$this->terminalItems = max(0, $terminalItems);
		$this->totalKnown = $totalKnown;
		$this->touch();
	}

	public function setStatus(string $status): void
	{
		if (!in_array($status, [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_PAUSED, self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS, self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
			throw new \InvalidArgumentException('Invalid batch status.');
		}
		$this->status = $status;
		$this->completedAt = in_array($status, [self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS, self::STATUS_FAILED, self::STATUS_CANCELLED], true) ? new \DateTimeImmutable() : null;
		$this->touch();
	}

	public function getProgressPercent(): ?int
	{
		if (!$this->totalKnown) {
			return null;
		}
		if (0 === $this->totalItems) {
			return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS], true) ? 100 : 0;
		}

		return min(100, (int) floor(100 * $this->terminalItems / $this->totalItems));
	}

	private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
