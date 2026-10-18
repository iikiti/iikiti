<?php

declare(strict_types=1);

namespace iikiti\CMS\Batch;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Entity\Batch\BatchSchedule;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

final class BatchScheduleRunner
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly ScheduledTaskProviderRegistry $providers,
		private readonly BatchManager $batches,
		private readonly DatabaseTableNameResolver $tableNames,
	) {
	}

	public function runDue(int $limit = 100): int
	{
		$now = new \DateTimeImmutable();
		$schedules = $this->entityManager->getRepository(BatchSchedule::class)->createQueryBuilder('schedule')->
			andWhere('schedule.enabled = :enabled')->
			andWhere('schedule.nextRunAt <= :now')->
			setParameter('enabled', true)->
			setParameter('now', $now)->
			orderBy('schedule.nextRunAt', 'ASC')->
			setMaxResults(max(1, min(500, $limit)))->
			getQuery()->
			getResult();
		$count = 0;

		foreach ($schedules as $schedule) {
			$this->runSchedule($schedule, $now);
			++$count;
		}
		$this->batches->recoverStalledItems();

		return $count;
	}

	private function runSchedule(BatchSchedule $schedule, \DateTimeImmutable $checkedAt): void
	{
		$id = $schedule->getId() ?? throw new \LogicException('Scheduled tasks must have a database ID.');
		$dueAt = $schedule->getNextRunAt();
		$occurrenceKey = $dueAt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
		$connection = $this->entityManager->getConnection();
		if (!$schedule->allowsOverlap()) {
			$active = $connection->fetchOne(
				$this->sql("SELECT 1 FROM batch_schedule_runs run INNER JOIN batch_jobs job ON job.id = run.batch_job_id WHERE run.schedule_id = :schedule AND job.status IN ('queued', 'running', 'paused') LIMIT 1"),
				['schedule' => $id],
			);
			if (false !== $active) {
				// Preserve the due occurrence until the active batch completes.
				return;
			}
		}

		try {
			$runId = $connection->fetchOne(
				$this->sql('INSERT INTO batch_schedule_runs (schedule_id, occurrence_key, status, batch_job_id, summary, started_at, completed_at) VALUES (:schedule, :occurrence, :status, NULL, :summary, :started, NULL) RETURNING id'),
				['schedule' => $id, 'occurrence' => $occurrenceKey, 'status' => 'running', 'summary' => 'Scheduled task started.', 'started' => $this->dbDate($checkedAt)],
			);
		} catch (UniqueConstraintViolationException) {
			return;
		}
		$this->advanceSchedule($schedule, $dueAt);

		try {
			$provider = $this->providers->get($schedule->getProviderKey());
			if ($provider->getScope() !== $schedule->getScope()) {
				throw new \InvalidArgumentException('Scheduled provider scope changed since this schedule was created.');
			}
			$options = $provider->validateOptions($schedule->getOptions());
			$job = $this->batches->submit(
				$schedule->getName(),
				$provider->createMessages($options, $schedule->getSiteId()),
				$provider->getKey(),
				$schedule->getSiteId(),
				$schedule->getCreatedByUserId(),
				3,
				$provider->getBatchConfiguration($options, $schedule->getSiteId()),
			);
			$jobId = $job->getId();
			$connection->executeStatement($this->sql('UPDATE batch_schedule_runs SET status = :status, batch_job_id = :job, summary = :summary, completed_at = :now WHERE id = :id'), [
				'status' => 'completed',
				'job' => $jobId,
				'summary' => sprintf('Started batch job %s.', (string) $jobId),
				'now' => $this->dbDate(new \DateTimeImmutable()),
				'id' => $runId,
			]);
		} catch (\Throwable $exception) {
			$connection->executeStatement($this->sql('UPDATE batch_schedule_runs SET status = :status, summary = :summary, completed_at = :now WHERE id = :id'), [
				'status' => 'failed',
				'summary' => sprintf('Scheduled task failed (%s).', $exception::class),
				'now' => $this->dbDate(new \DateTimeImmutable()),
				'id' => $runId,
			]);
		}
	}

	private function advanceSchedule(BatchSchedule $schedule, \DateTimeImmutable $dueAt): void
	{
		$next = CronExpressionTrigger::fromSpec($schedule->getCronExpression(), null, $schedule->getTimezone())->getNextRunDate($dueAt);
		if (null === $next) {
			$this->entityManager->getConnection()->executeStatement($this->sql('UPDATE batch_schedules SET enabled = FALSE, last_run_at = :lastRun, updated_at = :now WHERE id = :id'), ['lastRun' => $this->dbDate($dueAt), 'now' => $this->dbDate(new \DateTimeImmutable()), 'id' => $schedule->getId()]);
			$schedule->markRun($dueAt);

			return;
		}
		$this->entityManager->getConnection()->executeStatement($this->sql('UPDATE batch_schedules SET last_run_at = :lastRun, next_run_at = :nextRun, updated_at = :now WHERE id = :id'), ['lastRun' => $this->dbDate($dueAt), 'nextRun' => $this->dbDate($next), 'now' => $this->dbDate(new \DateTimeImmutable()), 'id' => $schedule->getId()]);
		$schedule->markRun($dueAt);
	}

	private function sql(string $sql): string
	{
		return $this->tableNames->qualifySql($sql);
	}

	private function dbDate(\DateTimeInterface $date): string
	{
		return $date->format('Y-m-d H:i:s');
	}
}
