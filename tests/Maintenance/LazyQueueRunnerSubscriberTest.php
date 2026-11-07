<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Maintenance;

use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Event\Subscriber\LazyQueueRunnerSubscriber;
use iikiti\CMS\Maintenance\BackgroundQueueApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class LazyQueueRunnerSubscriberTest extends TestCase
{
	public function testDisabledLazyModeDoesNotStartTheRunner(): void
	{
		$http = new MockHttpClient(static function (): never {
			self::fail('Disabled lazy mode must not send the trigger request.');
		});
		$subscriber = new LazyQueueRunnerSubscriber($this->runner($http), false, 'consumer');

		$subscriber(new TerminateEvent($this->createStub(HttpKernelInterface::class), new Request(), new Response()));
		self::assertSame(0, $http->getRequestsCount());
	}

	public function testApiRuntimeDoesNotTriggerItselfAfterEveryRequest(): void
	{
		$http = new MockHttpClient(static function (): never {
			self::fail('API runtime must use the external maintenance trigger, not recursively call itself.');
		});
		$subscriber = new LazyQueueRunnerSubscriber($this->runner($http), true, 'api');

		$subscriber(new TerminateEvent($this->createStub(HttpKernelInterface::class), new Request(), new Response()));
		self::assertSame(0, $http->getRequestsCount());
	}

	public function testRunnerFailureDoesNotChangeTheSentResponse(): void
	{
		$http = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 500]));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');
		$response = new Response('already sent', Response::HTTP_ACCEPTED);
		$subscriber = new LazyQueueRunnerSubscriber($this->runner($http), true, 'consumer', $logger);

		$subscriber(new TerminateEvent($this->createStub(HttpKernelInterface::class), new Request(), $response));

		self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
		self::assertSame('already sent', $response->getContent());
	}

	private function runner(MockHttpClient $http): BackgroundQueueApiClient
	{
		return new BackgroundQueueApiClient(
			new BusinessApiClient($http, 'https://api.example.test', 'test'),
			'background-trigger',
		);
	}
}
