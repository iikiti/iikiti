<?php

declare(strict_types=1);

namespace iikiti\CMS\Batch;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Entity\Batch\BatchEvent;
use iikiti\CMS\Entity\Batch\BatchItem;
use iikiti\CMS\Entity\Batch\BatchJob;
use iikiti\CMS\Message\ProcessBatchItem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class BatchManager
{
	private const FLUSH_SIZE = 100;

	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly BatchMessageHandlerRegistry $handlers,
		private readonly MessageBusInterface $messageBus,
		private readonly EventDispatcherInterface $eventDispatcher,
		private readonly DatabaseTableNameResolver $tableNames,
	) {
	}

	/**
	 * @param iterable<BatchMessage> $messages
	 * @param array<string,mixed>     $configuration
	 */
	public function submit(
		string $name,
		iterable $messages,
		?string $taskKey = null,
		int|string|null $siteId = null,
		int|string|null $createdByUserId = null,
		int $maxAttempts = 3,
		array $configuration = [],
		bool $deferDispatch = false,
	): BatchJob {
		$job = new BatchJob($name, $taskKey, $siteId, $createdByUserId, $maxAttempts, false, $configuration);
		$this->entityManager->persist($job);
		$this->entityManager->flush();
		$jobId = $job->getId() ?? throw new \LogicException('Persisted batch jobs must have an ID.');
		$connection = $this->entityManager->getConnection();
		$buffer = [];
		$total = 0;

		try {
			foreach ($messages as $message) {
				$this->handlers->get($message->type);
				$buffer[] = $message;
				++$total;
				if (count($buffer) >= self::FLUSH_SIZE) {
					$this->persistChunk($jobId, $buffer, $connection);
					$buffer = [];
				}
			}
			if ([] !== $buffer) {
				$this->persistChunk($jobId, $buffer, $connection);
			}

			$this->resolveDependencies($jobId, $connection);
			$this->assertAcyclic($jobId, $connection);
			$connection->executeStatement(
				$this->sql("UPDATE batch_jobs SET total_known = TRUE, total_items = :total, status = :status, updated_at = :now WHERE id = :id"),
				['total' => $total, 'status' => 0 === $total ? BatchJob::STATUS_COMPLETED : BatchJob::STATUS_QUEUED, 'now' => $this->now(), 'id' => $jobId],
			);
			if (0 < $total) {
				$this->publish($jobId, 'created', sprintf('Created batch with %d messages.', $total), ['total' => $total]);
				if (!$deferDispatch) {
					$this->releaseReadyItems($jobId, $connection);
				}
			} else {
				$this->publish($jobId, 'completed', 'Batch completed with no messages.');
				$this->eventDispatcher->dispatch(new BatchJobFinishedEvent($jobId, BatchJob::STATUS_COMPLETED));
			}

			$this->entityManager->clear();

			return $this->entityManager->find(BatchJob::class, $jobId) ?? throw new \LogicException('Batch job disappeared after submission.');
		} catch (\Throwable $exception) {
			$this->entityManager->clear();
			$failed = $this->entityManager->find(BatchJob::class, $jobId);
			if ($failed instanceof BatchJob) {
				$this->entityManager->remove($failed);
				$this->entityManager->flush();
			}
			$this->entityManager->clear();

			throw $exception;
		}
	}

	public function processItem(int|string $itemId): void
	{
		$connection = $this->entityManager->getConnection();
		$now = $this->now();
		$claimed = $connection->executeStatement(
			$this->sql("UPDATE batch_items SET state = :running, attempts = attempts + 1, locked_at = :now, updated_at = :now WHERE id = :id AND state IN (:queued, :retrying) AND EXISTS (SELECT 1 FROM batch_jobs j WHERE j.id = batch_job_id AND j.status NOT IN (:cancelled, :failed, :completed, :withErrors, :paused))"),
			[
				'running' => BatchItem::STATE_RUNNING,
				'now' => $now,
				'id' => $itemId,
				'queued' => BatchItem::STATE_QUEUED,
				'retrying' => BatchItem::STATE_RETRYING,
				'cancelled' => BatchJob::STATUS_CANCELLED,
				'failed' => BatchJob::STATUS_FAILED,
				'completed' => BatchJob::STATUS_COMPLETED,
				'withErrors' => BatchJob::STATUS_COMPLETED_WITH_ERRORS,
				'paused' => BatchJob::STATUS_PAUSED,
			],
		);
		if (1 !== $claimed) {
			return;
		}

		$this->entityManager->clear();
		$item = $this->entityManager->find(BatchItem::class, $itemId);
		if (!$item instanceof BatchItem) {
			return;
		}
		$job = $item->getJob();
		$jobId = $job->getId() ?? throw new \LogicException('Batch item job must be persisted.');
		if ($item->getAttempts() > $job->getMaxAttempts()) {
			$this->finishItem($itemId, BatchItem::STATE_FAILED, 'Worker stopped before completing the final allowed attempt.');
			return;
		}
		$context = new BatchMessageContext($jobId, $itemId, $item->getIdempotencyKey(), $this);
		$connection->executeStatement($this->sql('UPDATE batch_jobs SET status = :jobRunning, updated_at = :now WHERE id = :id AND status IN (:queued, :alreadyRunning)'), [
			'jobRunning' => BatchJob::STATUS_RUNNING,
			'now' => $now,
			'id' => $jobId,
			'queued' => BatchJob::STATUS_QUEUED,
			'alreadyRunning' => BatchJob::STATUS_RUNNING,
		]);
		$this->publish($jobId, 'item_started', $item->getSummary(), ['itemKey' => $item->getKey(), 'itemId' => (string) $itemId]);

		try {
			$handler = $this->handlers->get($item->getType());
			$dependencies = $connection->fetchFirstColumn(
				$this->sql(' SELECT dependency.item_key FROM batch_item_dependencies edge INNER JOIN batch_items dependency ON dependency.id = edge.dependency_item_id WHERE edge.dependent_item_id = :id'),
				['id' => $itemId],
			);
			$message = new BatchMessage($item->getKey(), $item->getType(), $item->getPayload(), $item->getSummary(), $item->getIdempotencyKey(), $dependencies, $item->getVersion());
			if ($context->isCancellationRequested()) {
				$this->finishItem($itemId, BatchItem::STATE_SKIPPED, 'Batch was cancelled.');
				return;
			}
			$handler->handle($message, $context);
			$this->finishItem($itemId, BatchItem::STATE_SUCCEEDED);
		} catch (BatchMessageSkipped $exception) {
			$this->finishItem($itemId, BatchItem::STATE_SKIPPED, $exception->getSafeReason());
		} catch (CredentialRefreshRequired $exception) {
			$this->pauseForCredentials($jobId, $itemId, $exception::class);
		} catch (PermanentBatchMessageFailure $exception) {
			$this->finishItem($itemId, BatchItem::STATE_FAILED, sprintf('Processing failed (%s).', $exception::class));
		} catch (\InvalidArgumentException $exception) {
			$this->finishItem($itemId, BatchItem::STATE_FAILED, sprintf('Stored message is no longer valid (%s).', $exception::class));
		} catch (\Throwable $exception) {
			$this->retryOrFail($item, $exception);
		} finally {
			$this->entityManager->clear();
		}
	}

	/** @param array<string,scalar|null> $details */
	public function report(int|string $jobId, string $type, string $summary, array $details = []): void
	{
		$this->publish($jobId, $type, $summary, $details);
	}

	public function isCancellationRequested(int|string $jobId): bool
	{
		return 1 === (int) $this->entityManager->getConnection()->fetchOne(
			$this->sql(' SELECT COUNT(*) FROM batch_jobs WHERE id = :id AND status = :status'),
			['id' => $jobId, 'status' => BatchJob::STATUS_CANCELLED],
		);
	}

	public function cancel(int|string $jobId): bool
	{
		$connection = $this->entityManager->getConnection();
		$updated = $connection->executeStatement(
			$this->sql("UPDATE batch_jobs SET status = :cancelled, completed_at = :now, updated_at = :now WHERE id = :id AND status IN (:queued, :running, :paused)"),
			['cancelled' => BatchJob::STATUS_CANCELLED, 'now' => $this->now(), 'id' => $jobId, 'queued' => BatchJob::STATUS_QUEUED, 'running' => BatchJob::STATUS_RUNNING, 'paused' => BatchJob::STATUS_PAUSED],
		);
		if (1 === $updated) {
			$skipped = $connection->executeStatement($this->sql("UPDATE batch_items SET state = :skipped, locked_at = NULL WHERE batch_job_id = :job AND state IN (:queued, :waiting, :retrying)"), [
				'skipped' => BatchItem::STATE_SKIPPED,
				'job' => $jobId,
				'queued' => BatchItem::STATE_QUEUED,
				'waiting' => BatchItem::STATE_WAITING,
				'retrying' => BatchItem::STATE_RETRYING,
			]);
			if (0 < $skipped) {
				$connection->executeStatement($this->sql('UPDATE batch_jobs SET terminal_items = terminal_items + :count WHERE id = :id'), ['count' => $skipped, 'id' => $jobId]);
			}
			$this->publish($jobId, 'cancelled', 'Batch cancellation requested.');
			$this->eventDispatcher->dispatch(new BatchJobFinishedEvent($jobId, BatchJob::STATUS_CANCELLED));
		}

		return 1 === $updated;
	}

	public function retryFailed(int|string $jobId): int
	{
		$connection = $this->entityManager->getConnection();
		$updated = $connection->executeStatement(
			$this->sql("UPDATE batch_items SET state = CASE WHEN EXISTS (SELECT 1 FROM batch_item_dependencies d WHERE d.dependent_item_id = batch_items.id) THEN :waiting ELSE :queued END, attempts = 0, last_error = NULL, locked_at = NULL, updated_at = :now WHERE batch_job_id = :job AND state IN (:failed, :skipped)"),
			['waiting' => BatchItem::STATE_WAITING, 'queued' => BatchItem::STATE_QUEUED, 'now' => $this->now(), 'job' => $jobId, 'failed' => BatchItem::STATE_FAILED, 'skipped' => BatchItem::STATE_SKIPPED],
		);
		if (0 === $updated) {
			return 0;
		}
		$succeeded = (int) $connection->fetchOne($this->sql('SELECT COUNT(*) FROM batch_items WHERE batch_job_id = :job AND state = :succeeded'), ['job' => $jobId, 'succeeded' => BatchItem::STATE_SUCCEEDED]);
		$connection->executeStatement($this->sql('UPDATE batch_jobs SET status = :queued, terminal_items = :terminal, completed_at = NULL, updated_at = :now WHERE id = :job AND status IN (:withErrors, :failed)'), [
			'queued' => BatchJob::STATUS_QUEUED,
			'terminal' => $succeeded,
			'now' => $this->now(),
			'job' => $jobId,
			'withErrors' => BatchJob::STATUS_COMPLETED_WITH_ERRORS,
			'failed' => BatchJob::STATUS_FAILED,
		]);
		$this->publish($jobId, 'retry', sprintf('Requeued %d failed or skipped items.', $updated), ['items' => $updated]);
		$this->releaseReadyItems($jobId, $connection);

		return $updated;
	}

	public function start(int|string $jobId): bool
	{
		$connection = $this->entityManager->getConnection();
		$updated = $connection->executeStatement($this->sql('UPDATE batch_jobs SET status = :queued, updated_at = :now WHERE id = :id AND status = :queued'), [
			'queued' => BatchJob::STATUS_QUEUED,
			'now' => $this->now(),
			'id' => $jobId,
		]);
		if (1 !== $updated) {
			return false;
		}
		$this->releaseReadyItems($jobId, $connection);

		return true;
	}

	public function pauseForCredentials(int|string $jobId, int|string $itemId, string $reason): void
	{
		$connection = $this->entityManager->getConnection();
		$connection->executeStatement($this->sql('UPDATE batch_items SET state = :retrying, locked_at = NULL, last_error = :reason, updated_at = :now WHERE id = :id AND state = :running'), [
			'retrying' => BatchItem::STATE_RETRYING,
			'reason' => sprintf('Source credentials need refreshing (%s).', $reason),
			'now' => $this->now(),
			'id' => $itemId,
			'running' => BatchItem::STATE_RUNNING,
		]);
		$connection->executeStatement($this->sql('UPDATE batch_jobs SET status = :paused, updated_at = :now WHERE id = :id AND status IN (:queued, :running)'), [
			'paused' => BatchJob::STATUS_PAUSED,
			'now' => $this->now(),
			'id' => $jobId,
			'queued' => BatchJob::STATUS_QUEUED,
			'running' => BatchJob::STATUS_RUNNING,
		]);
		$this->publish($jobId, 'credential_required', 'Source API credentials expired. Refresh credentials to resume this batch.');
	}

	public function resume(int|string $jobId): bool
	{
		$connection = $this->entityManager->getConnection();
		$updated = $connection->executeStatement($this->sql('UPDATE batch_jobs SET status = :running, updated_at = :now WHERE id = :id AND status = :paused'), [
			'running' => BatchJob::STATUS_RUNNING,
			'now' => $this->now(),
			'id' => $jobId,
			'paused' => BatchJob::STATUS_PAUSED,
		]);
		if (1 !== $updated) {
			return false;
		}
		$this->publish($jobId, 'resumed', 'Batch resumed with refreshed credentials.');
		$this->releaseReadyItems($jobId, $connection);
		foreach ($connection->fetchFirstColumn($this->sql('SELECT id FROM batch_items WHERE batch_job_id = :job AND state = :retrying'), ['job' => $jobId, 'retrying' => BatchItem::STATE_RETRYING]) as $itemId) {
			$this->messageBus->dispatch(new ProcessBatchItem($itemId));
		}

		return true;
	}

	public function recoverStalledItems(int $timeoutSeconds = 900): int
	{
		$connection = $this->entityManager->getConnection();
		$stalledIds = $connection->fetchFirstColumn(
			$this->sql("SELECT id FROM batch_items WHERE state = :running AND locked_at < :expired ORDER BY id LIMIT 500"),
			['running' => BatchItem::STATE_RUNNING, 'expired' => (new \DateTimeImmutable(sprintf('-%d seconds', max(60, $timeoutSeconds))))->format('Y-m-d H:i:s')],
		);
		foreach ($stalledIds as $id) {
			$updated = $connection->executeStatement($this->sql('UPDATE batch_items SET state = :queued, locked_at = NULL WHERE id = :id AND state = :running'), [
				'queued' => BatchItem::STATE_QUEUED,
				'id' => $id,
				'running' => BatchItem::STATE_RUNNING,
			]);
			if (1 === $updated) {
				$this->messageBus->dispatch(new ProcessBatchItem($id));
			}
		}

		return count($stalledIds);
	}

	/** @param list<BatchMessage> $messages */
	private function persistChunk(int|string $jobId, array $messages, Connection $connection): void
	{
		$job = $this->entityManager->getReference(BatchJob::class, $jobId);
		$items = [];
		foreach ($messages as $message) {
			$items[] = new BatchItem($job, $message->key, $message->type, $message->version, $message->payload, $message->summary, $message->idempotencyKey, [] !== $message->dependsOn);
		}
		foreach ($items as $item) {
			$this->entityManager->persist($item);
		}
		$this->entityManager->flush();
		foreach ($messages as $index => $message) {
			if ([] === $message->dependsOn) {
				continue;
			}
			$itemId = $items[$index]->getId() ?? throw new \LogicException('Batch item was not assigned an ID.');
			foreach ($message->dependsOn as $dependencyKey) {
				$connection->executeStatement($this->sql('INSERT INTO batch_item_dependency_refs (dependent_item_id, dependency_key) VALUES (:item, :dependency)'), ['item' => $itemId, 'dependency' => $dependencyKey]);
			}
		}
		$this->entityManager->clear();
	}

	private function resolveDependencies(int|string $jobId, Connection $connection): void
	{
		$missing = (int) $connection->fetchOne(
			$this->sql(' SELECT COUNT(*) FROM batch_item_dependency_refs r INNER JOIN batch_items source ON source.id = r.dependent_item_id LEFT JOIN batch_items target ON target.batch_job_id = source.batch_job_id AND target.item_key = r.dependency_key WHERE source.batch_job_id = :job AND target.id IS NULL'),
			['job' => $jobId],
		);
		if (0 < $missing) {
			throw new \InvalidArgumentException(sprintf('Batch contains %d missing dependency reference(s).', $missing));
		}
		$connection->executeStatement(
			$this->sql(' INSERT INTO batch_item_dependencies (dependent_item_id, dependency_item_id) SELECT r.dependent_item_id, target.id FROM batch_item_dependency_refs r INNER JOIN batch_items source ON source.id = r.dependent_item_id INNER JOIN batch_items target ON target.batch_job_id = source.batch_job_id AND target.item_key = r.dependency_key WHERE source.batch_job_id = :job'),
			['job' => $jobId],
		);
		$connection->executeStatement(
			$this->sql(' DELETE FROM batch_item_dependency_refs WHERE dependent_item_id IN (SELECT id FROM batch_items WHERE batch_job_id = :job)'),
			['job' => $jobId],
		);
	}

	private function assertAcyclic(int|string $jobId, Connection $connection): void
	{
		$cycle = $connection->fetchOne(
			$this->sql(' WITH RECURSIVE dependency_walk(start_id, current_id, path, cycle) AS (SELECT dependency_item_id, dependent_item_id, ARRAY[dependency_item_id, dependent_item_id], dependency_item_id = dependent_item_id FROM batch_item_dependencies d INNER JOIN batch_items i ON i.id = d.dependent_item_id WHERE i.batch_job_id = :job UNION ALL SELECT w.start_id, d.dependent_item_id, w.path || d.dependent_item_id, d.dependent_item_id = ANY(w.path) FROM dependency_walk w INNER JOIN batch_item_dependencies d ON d.dependency_item_id = w.current_id WHERE NOT w.cycle) SELECT 1 FROM dependency_walk WHERE cycle LIMIT 1'),
			['job' => $jobId],
		);
		if (false !== $cycle) {
			throw new \InvalidArgumentException('Batch dependencies must form an acyclic graph.');
		}
	}

	private function releaseReadyItems(int|string $jobId, Connection $connection): void
	{
		$ids = $connection->fetchFirstColumn(
			$this->sql(' UPDATE batch_items i SET state = :queued FROM batch_jobs j WHERE i.batch_job_id = j.id AND j.id = :job AND i.state = :waiting AND NOT EXISTS (SELECT 1 FROM batch_item_dependencies d WHERE d.dependent_item_id = i.id AND (SELECT state FROM batch_items dependency WHERE dependency.id = d.dependency_item_id) <> :success) RETURNING i.id'),
			['queued' => BatchItem::STATE_QUEUED, 'job' => $jobId, 'waiting' => BatchItem::STATE_WAITING, 'success' => BatchItem::STATE_SUCCEEDED],
		);
		$rootIds = $connection->fetchFirstColumn(
			$this->sql(' SELECT i.id FROM batch_items i WHERE i.batch_job_id = :job AND i.state = :queued AND NOT EXISTS (SELECT 1 FROM batch_item_dependencies d INNER JOIN batch_items dependency ON dependency.id = d.dependency_item_id WHERE d.dependent_item_id = i.id AND dependency.state <> :success)'),
			['job' => $jobId, 'queued' => BatchItem::STATE_QUEUED, 'success' => BatchItem::STATE_SUCCEEDED],
		);
		foreach (array_unique([...$ids, ...$rootIds]) as $id) {
			$this->messageBus->dispatch(new ProcessBatchItem($id));
		}
	}

	private function retryOrFail(BatchItem $item, \Throwable $exception): void
	{
		$job = $item->getJob();
		$jobId = $job->getId() ?? throw new \LogicException('Batch job must be persisted.');
		if ($item->getAttempts() < $job->getMaxAttempts() && !$exception instanceof PermanentBatchMessageFailure) {
			$safeError = sprintf('Processing failed (%s).', $exception::class);
			$this->entityManager->getConnection()->executeStatement(
				$this->sql(' UPDATE batch_items SET state = :retrying, locked_at = NULL, last_error = :error, updated_at = :now WHERE id = :id AND state = :running'),
				['retrying' => BatchItem::STATE_RETRYING, 'error' => $safeError, 'now' => $this->now(), 'id' => $item->getId(), 'running' => BatchItem::STATE_RUNNING],
			);
			$this->publish($jobId, 'item_retrying', sprintf('%s; retry %d of %d.', $item->getSummary(), $item->getAttempts(), $job->getMaxAttempts()), ['itemKey' => $item->getKey(), 'attempt' => $item->getAttempts()]);
			$this->messageBus->dispatch(new ProcessBatchItem($item->getId() ?? throw new \LogicException('Batch item must be persisted.')));
			return;
		}
		$this->finishItem($item->getId() ?? throw new \LogicException('Batch item must be persisted.'), BatchItem::STATE_FAILED, sprintf('Processing failed (%s).', $exception::class));
	}

	private function finishItem(int|string $itemId, string $state, ?string $error = null): void
	{
		$this->entityManager->clear();
		$item = $this->entityManager->find(BatchItem::class, $itemId);
		if (!$item instanceof BatchItem) {
			return;
		}
		$jobId = $item->getJob()->getId() ?? throw new \LogicException('Batch job must be persisted.');
		$item->setState($state, $error);
		$this->entityManager->flush();
		$connection = $this->entityManager->getConnection();
		$skippedCount = 0;
		if (in_array($state, [BatchItem::STATE_FAILED, BatchItem::STATE_SKIPPED], true)) {
			$skippedCount = (int) $connection->fetchOne(
				$this->sql(' WITH RECURSIVE descendants(id) AS (SELECT dependent_item_id FROM batch_item_dependencies WHERE dependency_item_id = :failed UNION SELECT d.dependent_item_id FROM batch_item_dependencies d INNER JOIN descendants x ON d.dependency_item_id = x.id), changed AS (UPDATE batch_items SET state = :skipped, last_error = :error, locked_at = NULL, updated_at = :now WHERE id IN (SELECT id FROM descendants) AND state IN (:waiting, :queued, :retrying) RETURNING id) SELECT COUNT(*) FROM changed'),
				['failed' => $itemId, 'skipped' => BatchItem::STATE_SKIPPED, 'error' => 'A required dependency failed.', 'now' => $this->now(), 'waiting' => BatchItem::STATE_WAITING, 'queued' => BatchItem::STATE_QUEUED, 'retrying' => BatchItem::STATE_RETRYING],
			);
			if (0 < $skippedCount) {
				$connection->executeStatement($this->sql('UPDATE batch_jobs SET terminal_items = terminal_items + :count WHERE id = :id'), ['count' => $skippedCount, 'id' => $jobId]);
			}
		}
		$connection->executeStatement($this->sql('UPDATE batch_jobs SET terminal_items = terminal_items + 1, updated_at = :now WHERE id = :id'), ['now' => $this->now(), 'id' => $jobId]);
		$this->publish($jobId, 'item_'.$state, $error ? mb_substr($error, 0, 500) : $item->getSummary(), ['itemKey' => $item->getKey(), 'itemId' => (string) $itemId, 'skippedDependents' => $skippedCount]);

		if (BatchItem::STATE_SUCCEEDED === $state) {
			$ready = $connection->fetchFirstColumn(
				$this->sql(' UPDATE batch_items i SET state = :queued WHERE i.state = :waiting AND EXISTS (SELECT 1 FROM batch_item_dependencies d WHERE d.dependent_item_id = i.id AND d.dependency_item_id = :finished) AND NOT EXISTS (SELECT 1 FROM batch_item_dependencies d INNER JOIN batch_items dependency ON dependency.id = d.dependency_item_id WHERE d.dependent_item_id = i.id AND dependency.state <> :success) AND i.batch_job_id = :job RETURNING i.id'),
				['queued' => BatchItem::STATE_QUEUED, 'waiting' => BatchItem::STATE_WAITING, 'finished' => $itemId, 'success' => BatchItem::STATE_SUCCEEDED, 'job' => $jobId],
			);
			foreach ($ready as $id) {
				$this->messageBus->dispatch(new ProcessBatchItem($id));
			}
		}

		$total = (int) $connection->fetchOne($this->sql('SELECT total_items FROM batch_jobs WHERE id = :id'), ['id' => $jobId]);
		$terminal = (int) $connection->fetchOne($this->sql('SELECT terminal_items FROM batch_jobs WHERE id = :id'), ['id' => $jobId]);
		if ($terminal >= $total) {
			$hasErrors = (int) $connection->fetchOne($this->sql('SELECT COUNT(*) FROM batch_items WHERE batch_job_id = :id AND state IN (:failed, :skipped)'), ['id' => $jobId, 'failed' => BatchItem::STATE_FAILED, 'skipped' => BatchItem::STATE_SKIPPED]);
			$finalStatus = 0 < $hasErrors ? BatchJob::STATUS_COMPLETED_WITH_ERRORS : BatchJob::STATUS_COMPLETED;
			$connection->executeStatement($this->sql('UPDATE batch_jobs SET status = :status, completed_at = :now, updated_at = :now WHERE id = :id AND status NOT IN (:cancelled, :failed)'), ['status' => $finalStatus, 'now' => $this->now(), 'id' => $jobId, 'cancelled' => BatchJob::STATUS_CANCELLED, 'failed' => BatchJob::STATUS_FAILED]);
			$this->publish($jobId, $finalStatus, 'Batch '.$finalStatus.'.');
			$this->eventDispatcher->dispatch(new BatchJobFinishedEvent($jobId, $finalStatus));
		}
		$this->entityManager->clear();
	}

	/** @param array<string,scalar|null> $details */
	private function publish(int|string $jobId, string $type, string $summary, array $details = []): void
	{
		$job = $this->entityManager->find(BatchJob::class, $jobId);
		if (!$job instanceof BatchJob) {
			return;
		}
		$this->entityManager->persist(new BatchEvent($job, $type, $summary, $details));
		$this->entityManager->flush();
	}

	private function sql(string $sql): string
	{
		return $this->tableNames->qualifySql($sql);
	}

	private function now(): string
	{
		return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
	}
}
