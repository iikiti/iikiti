<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Maintenance;

use Doctrine\DBAL\Connection;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Maintenance\BackgroundQueueRunner;
use iikiti\CMS\Message\RunDueBatchSchedules;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

final class BackgroundQueueRunnerTest extends TestCase
{
	public function testClaimsSharedCooldownAndConsumesOnlyEligibleReceiversWithinMessageLimit(): void
	{
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::exactly(3))->method('fetchOne')->willReturnCallback(static function (string $sql): mixed {
			if (str_contains($sql, 'pg_try_advisory_lock')) {
				return 1;
			}
			if (str_contains($sql, 'UPDATE maintenance_runner_state')) {
				return '1';
			}
			if (str_contains($sql, 'pg_advisory_unlock')) {
				return 1;
			}

			self::fail('Unexpected database query.');
		});

		$receiver = new class implements ReceiverInterface {
			/** @var list<Envelope> */
			private array $messages = [];
			public int $acknowledged = 0;

			public function __construct()
			{
				$this->messages = [new Envelope(new \stdClass()), new Envelope(new \stdClass()), new Envelope(new \stdClass())];
			}

			public function get(): iterable
			{
				if ([] !== $this->messages) {
					yield array_shift($this->messages);
				}
			}

			public function ack(Envelope $envelope): void
			{
				++$this->acknowledged;
			}

			public function reject(Envelope $envelope): void
			{
			}
		};
		$receivers = $this->createMock(ServiceProviderInterface::class);
		$receivers->expects(self::once())->method('getProvidedServices')->willReturn([
			'batch_processing' => ReceiverInterface::class,
			'failed' => ReceiverInterface::class,
		]);
		$receivers->expects(self::once())->method('get')->with('batch_processing')->willReturn($receiver);

		$bus = $this->createMock(MessageBusInterface::class);
		$bus->expects(self::exactly(3))->method('dispatch')->willReturnCallback(static function (object $message): Envelope {
			if ($message instanceof Envelope) {
				return $message->with(new HandledStamp(null, 'test_handler'));
			}

			self::assertInstanceOf(RunDueBatchSchedules::class, $message);

			return new Envelope($message);
		});

		$runner = new BackgroundQueueRunner(
			$connection,
			new DatabaseTableNameResolver($connection),
			$bus,
			$receivers,
			new EventDispatcher(),
			cooldownSeconds: 0,
			maxMessages: 2,
			timeLimitSeconds: 1,
			excludedReceivers: ['failed'],
		);

		self::assertSame(2, $runner->run());
		self::assertSame(2, $receiver->acknowledged);
	}

	public function testSkipsTickWhenAnotherRunnerOwnsTheAdvisoryLock(): void
	{
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::once())->method('fetchOne')->willReturn(0);
		$receivers = $this->createMock(ServiceProviderInterface::class);
		$receivers->expects(self::never())->method('getProvidedServices');
		$bus = $this->createMock(MessageBusInterface::class);
		$bus->expects(self::never())->method('dispatch');

		$runner = new BackgroundQueueRunner(
			$connection,
			new DatabaseTableNameResolver($connection),
			$bus,
			$receivers,
			new EventDispatcher(),
		);

		self::assertSame(0, $runner->run());
	}
}
