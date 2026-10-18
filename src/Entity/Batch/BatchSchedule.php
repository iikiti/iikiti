<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Batch;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

#[ORM\Entity]
#[ORM\Table(name: 'batch_schedules')]
#[ORM\Index(name: 'idx_batch_schedule_due', columns: ['enabled', 'next_run_at'])]
#[ORM\Index(name: 'idx_batch_schedule_site', columns: ['site_id', 'enabled'])]
class BatchSchedule
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\Column(type: Types::STRING, length: 128)]
	private string $name;

	#[ORM\Column(name: 'provider_key', type: Types::STRING, length: 128)]
	private string $providerKey;

	#[ORM\Column(name: 'cron_expression', type: Types::STRING, length: 128)]
	private string $cronExpression;

	#[ORM\Column(type: Types::STRING, length: 64)]
	private string $timezone;

	#[ORM\Column(type: Types::STRING, length: 16)]
	private string $scope;

	#[ORM\Column(name: 'site_id', type: Types::BIGINT, nullable: true)]
	private int|string|null $siteId;

	/** @var array<string,mixed> */
	#[ORM\Column(type: Types::JSON)]
	private array $options;

	#[ORM\Column(type: Types::BOOLEAN)]
	private bool $enabled;

	#[ORM\Column(name: 'allow_overlap', type: Types::BOOLEAN)]
	private bool $allowOverlap;

	#[ORM\Column(name: 'created_by_user_id', type: Types::BIGINT, nullable: true)]
	private int|string|null $createdByUserId;

	#[ORM\Column(name: 'last_run_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
	private ?\DateTimeImmutable $lastRunAt = null;

	#[ORM\Column(name: 'next_run_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $nextRunAt;

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $updatedAt;

	/** @param array<string,mixed> $options */
	public function __construct(string $name, string $providerKey, string $cronExpression, string $timezone, string $scope, int|string|null $siteId, array $options, bool $enabled, bool $allowOverlap, int|string|null $createdByUserId)
	{
		$this->createdAt = new \DateTimeImmutable();
		$this->updatedAt = $this->createdAt;
		$this->update($name, $providerKey, $cronExpression, $timezone, $scope, $siteId, $options, $enabled, $allowOverlap);
		$this->createdByUserId = $createdByUserId;
	}

	public function getId(): int|string|null { return $this->id; }
	public function getName(): string { return $this->name; }
	public function getProviderKey(): string { return $this->providerKey; }
	public function getCronExpression(): string { return $this->cronExpression; }
	public function getTimezone(): string { return $this->timezone; }
	public function getScope(): string { return $this->scope; }
	public function getSiteId(): int|string|null { return $this->siteId; }
	/** @return array<string,mixed> */ public function getOptions(): array { return $this->options; }
	public function isEnabled(): bool { return $this->enabled; }
	public function allowsOverlap(): bool { return $this->allowOverlap; }
	public function getCreatedByUserId(): int|string|null { return $this->createdByUserId; }
	public function getLastRunAt(): ?\DateTimeImmutable { return $this->lastRunAt; }
	public function getNextRunAt(): \DateTimeImmutable { return $this->nextRunAt; }

	/** @param array<string,mixed> $options */
	public function update(string $name, string $providerKey, string $cronExpression, string $timezone, string $scope, int|string|null $siteId, array $options, bool $enabled, bool $allowOverlap): void
	{
		$name = trim($name);
		if ('' === $name || mb_strlen($name) > 128 || !preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $providerKey)) {
			throw new \InvalidArgumentException('Schedule name or provider key is invalid.');
		}
		if (!in_array($scope, ['site', 'global'], true) || ('site' === $scope && null === $siteId) || ('global' === $scope && null !== $siteId)) {
			throw new \InvalidArgumentException('Schedule scope and site must agree.');
		}
		try {
			$trigger = CronExpressionTrigger::fromSpec($cronExpression, null, $timezone);
			$next = $trigger->getNextRunDate(new \DateTimeImmutable());
		} catch (\Throwable $exception) {
			throw new \InvalidArgumentException('Schedule recurrence or timezone is invalid.', previous: $exception);
		}
		if (null === $next) {
			throw new \InvalidArgumentException('Schedule does not have a next execution time.');
		}
		$this->name = $name;
		$this->providerKey = $providerKey;
		$this->cronExpression = $cronExpression;
		$this->timezone = $timezone;
		$this->scope = $scope;
		$this->siteId = $siteId;
		$this->options = $options;
		$this->enabled = $enabled;
		$this->allowOverlap = $allowOverlap;
		$this->nextRunAt = $next;
		$this->updatedAt = new \DateTimeImmutable();
	}

	public function markRun(\DateTimeImmutable $time): void
	{
		$this->lastRunAt = $time;
		$next = CronExpressionTrigger::fromSpec($this->cronExpression, null, $this->timezone)->getNextRunDate($time);
		if (null === $next) {
			$this->enabled = false;
			return;
		}
		$this->nextRunAt = $next;
		$this->updatedAt = new \DateTimeImmutable();
	}
}
