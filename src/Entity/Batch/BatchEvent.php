<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Batch;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'batch_events')]
#[ORM\Index(name: 'idx_batch_event_job_id', columns: ['batch_job_id', 'id'])]
class BatchEvent
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\ManyToOne(targetEntity: BatchJob::class)]
	#[ORM\JoinColumn(name: 'batch_job_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
	private BatchJob $job;

	#[ORM\Column(name: 'event_type', type: Types::STRING, length: 32)]
	private string $type;

	#[ORM\Column(type: Types::STRING, length: 512)]
	private string $summary;

	/** @var array<string,scalar|null> */
	#[ORM\Column(type: Types::JSON)]
	private array $details;

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	/** @param array<string,scalar|null> $details */
	public function __construct(BatchJob $job, string $type, string $summary, array $details = [])
	{
		if (!preg_match('/^[a-z][a-z0-9_.-]{0,31}$/D', $type)) {
			throw new \InvalidArgumentException('Invalid batch event type.');
		}
		$this->job = $job;
		$this->type = $type;
		$summary = preg_replace('/(token|password|secret|credential)\\s*[:=]\\s*\\S+/i', '$1=[redacted]', $summary) ?? 'Batch event';
		$this->summary = mb_substr(trim($summary), 0, 512);
		$this->details = self::sanitizeDetails($details);
		$this->createdAt = new \DateTimeImmutable();
	}

	public function getId(): int|string|null { return $this->id; }
	public function getJob(): BatchJob { return $this->job; }
	public function getType(): string { return $this->type; }
	public function getSummary(): string { return $this->summary; }
	/** @return array<string,scalar|null> */ public function getDetails(): array { return $this->details; }
	public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

	/** @param array<string,scalar|null> $details
	 *  @return array<string,scalar|null>
	 */
	private static function sanitizeDetails(array $details): array
	{
		$safe = [];
		foreach ($details as $key => $value) {
			if (preg_match('/password|token|secret|credential|payload|content/i', $key)) {
				continue;
			}
			$safe[$key] = is_string($value) ? mb_substr($value, 0, 512) : $value;
			if (count($safe) >= 20) {
				break;
			}
		}

		return $safe;
	}
}
