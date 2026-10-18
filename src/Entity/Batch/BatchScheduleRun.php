<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Batch;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'batch_schedule_runs')]
#[ORM\UniqueConstraint(name: 'uniq_batch_schedule_occurrence', columns: ['schedule_id', 'occurrence_key'])]
#[ORM\Index(name: 'idx_batch_schedule_run_state', columns: ['schedule_id', 'status'])]
class BatchScheduleRun
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: BatchSchedule::class)]
	#[ORM\JoinColumn(name: 'schedule_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
	private BatchSchedule $schedule;

	#[ORM\Column(name: 'occurrence_key', type: Types::STRING, length: 128)]
	private string $occurrenceKey;

	#[ORM\Column(type: Types::STRING, length: 24)]
	private string $status;

	#[ORM\Column(name: 'batch_job_id', type: Types::BIGINT, nullable: true)]
	private int|string|null $batchJobId = null;

	#[ORM\Column(type: Types::STRING, length: 512)]
	private string $summary;

	#[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $startedAt;

	#[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
	private ?\DateTimeImmutable $completedAt = null;

	public function __construct(BatchSchedule $schedule, string $occurrenceKey)
	{
		$this->schedule = $schedule;
		$this->occurrenceKey = $occurrenceKey;
		$this->status = 'running';
		$this->summary = 'Scheduled task started.';
		$this->startedAt = new \DateTimeImmutable();
	}

	public function getId(): int|string|null { return $this->id; }
	public function getSchedule(): BatchSchedule { return $this->schedule; }
	public function getOccurrenceKey(): string { return $this->occurrenceKey; }
	public function getStatus(): string { return $this->status; }
	public function getBatchJobId(): int|string|null { return $this->batchJobId; }
	public function getSummary(): string { return $this->summary; }

	public function complete(string $status, string $summary, int|string|null $batchJobId = null): void
	{
		if (!in_array($status, ['completed', 'skipped', 'failed'], true)) {
			throw new \InvalidArgumentException('Invalid schedule run status.');
		}
		$this->status = $status;
		$this->summary = mb_substr($summary, 0, 512);
		$this->batchJobId = $batchJobId;
		$this->completedAt = new \DateTimeImmutable();
	}
}
