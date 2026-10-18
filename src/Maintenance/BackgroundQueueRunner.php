<?php

declare(strict_types=1);

namespace iikiti\CMS\Maintenance;

use Doctrine\DBAL\Connection;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Message\RunDueBatchSchedules;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\ScopedEventDispatcher;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Runs one small, shared tick of due schedules and asynchronous Messenger work.
 */
final class BackgroundQueueRunner
{
	private const ADVISORY_LOCK_ID = 724113905221;

	/**
	 * @param ServiceProviderInterface<ReceiverInterface> $receiverLocator
	 * @param list<string>                                $excludedReceivers
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly DatabaseTableNameResolver $tableNames,
		private readonly MessageBusInterface $messageBus,
		#[Autowire(service: 'messenger.receiver_locator')]
		private readonly ServiceProviderInterface $receiverLocator,
		private readonly EventDispatcherInterface $eventDispatcher,
		private readonly ?LoggerInterface $logger = null,
		#[Autowire('%background_runner_cooldown_seconds%')]
		private readonly int $cooldownSeconds = 5,
		#[Autowire('%background_runner_max_messages%')]
		private readonly int $maxMessages = 5,
		#[Autowire('%background_runner_time_limit_seconds%')]
		private readonly float $timeLimitSeconds = 2.0,
		#[Autowire('%background_runner_excluded_receivers%')]
		private readonly array $excludedReceivers = ['failed'],
	) {
	}

	/**
	 * Processes due schedules, then at most one bounded batch of queued messages.
	 * Returns the number of queue messages handled (including failed attempts).
	 */
	public function run(): int
	{
		if (!$this->acquireLock()) {
			return 0;
		}

		try {
			if (!$this->claimCooldown()) {
				return 0;
			}

			$this->messageBus->dispatch(new RunDueBatchSchedules());

			$receivers = $this->getReceivers();
			if ([] === $receivers) {
				return 0;
			}

			$workerDispatcher = new ScopedEventDispatcher($this->eventDispatcher);
			$messageCounter = new BoundedWorkerMessageCounter(max(1, min(100, $this->maxMessages)));
			$workerDispatcher->addListener(WorkerMessageHandledEvent::class, $messageCounter->onMessage(...));
			$workerDispatcher->addListener(WorkerMessageFailedEvent::class, $messageCounter->onMessage(...));

			$worker = new Worker($receivers, $this->messageBus, $workerDispatcher, $this->logger);
			$messageCounter->setWorker($worker);
			$worker->run([
				'sleep' => 0,
				'time_limit' => max(0.1, min(10.0, $this->timeLimitSeconds)),
				'stop_when_empty' => true,
				'fetch_size' => 1,
			]);

			return $messageCounter->getProcessedCount();
		} finally {
			$this->connection->fetchOne('SELECT pg_advisory_unlock('.self::ADVISORY_LOCK_ID.')');
		}
	}

	/** @return array<string,ReceiverInterface> */
	private function getReceivers(): array
	{
		$excluded = array_fill_keys(array_map('trim', $this->excludedReceivers), true);
		$receivers = [];
		foreach ($this->receiverLocator->getProvidedServices() as $name => $_type) {
			if (isset($excluded[$name])) {
				continue;
			}

			$receivers[$name] = $this->receiverLocator->get($name);
		}

		return $receivers;
	}

	private function acquireLock(): bool
	{
		return 1 === (int) $this->connection->fetchOne('SELECT CASE WHEN pg_try_advisory_lock('.self::ADVISORY_LOCK_ID.') THEN 1 ELSE 0 END');
	}

	private function claimCooldown(): bool
	{
		$now = new \DateTimeImmutable();
		$nextAllowedAt = $now->modify(sprintf('+%d seconds', max(0, min(3600, $this->cooldownSeconds))));
		$id = $this->connection->fetchOne(
			$this->tableNames->qualifySql('UPDATE maintenance_runner_state SET next_allowed_at = :next WHERE id = 1 AND next_allowed_at <= :now RETURNING id'),
			['next' => $nextAllowedAt->format('Y-m-d H:i:s'), 'now' => $now->format('Y-m-d H:i:s')],
		);

		return false !== $id;
	}
}
