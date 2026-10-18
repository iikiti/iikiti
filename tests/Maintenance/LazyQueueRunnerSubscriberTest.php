<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Maintenance;

use Doctrine\DBAL\Connection;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Event\Subscriber\LazyQueueRunnerSubscriber;
use iikiti\CMS\Maintenance\BackgroundQueueRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

final class LazyQueueRunnerSubscriberTest extends TestCase
{
	public function testDisabledLazyModeDoesNotStartTheRunner(): void
	{
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::never())->method('fetchOne');
		$runner = new BackgroundQueueRunner(
			$connection,
			new DatabaseTableNameResolver($connection),
			$this->createStub(MessageBusInterface::class),
			$this->createStub(ServiceProviderInterface::class),
			new EventDispatcher(),
		);
		$subscriber = new LazyQueueRunnerSubscriber($runner, false);

		$subscriber(new TerminateEvent($this->createStub(HttpKernelInterface::class), new Request(), new Response()));
	}

	public function testRunnerFailureDoesNotChangeTheSentResponse(): void
	{
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::once())->method('fetchOne')->willThrowException(new \RuntimeException('Database unavailable.'));
		$runner = new BackgroundQueueRunner(
			$connection,
			new DatabaseTableNameResolver($connection),
			$this->createStub(MessageBusInterface::class),
			$this->createStub(ServiceProviderInterface::class),
			new EventDispatcher(),
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');
		$response = new Response('already sent', Response::HTTP_ACCEPTED);
		$subscriber = new LazyQueueRunnerSubscriber($runner, true, $logger);

		$subscriber(new TerminateEvent($this->createStub(HttpKernelInterface::class), new Request(), $response));

		self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
		self::assertSame('already sent', $response->getContent());
	}
}
