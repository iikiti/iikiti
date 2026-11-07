<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Maintenance;

use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Maintenance\BackgroundQueueApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BackgroundQueueApiClientTest extends TestCase
{
	public function testCallsTheNarrowMaintenanceTriggerRoute(): void
	{
		$client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('POST', $method);
			self::assertSame('https://api.example.test/api/internal/maintenance/run-background-queue', $url);
			self::assertTrue(self::hasHeader($options['headers'], 'X-BACKGROUND-QUEUE-TRIGGER', 'trigger-secret'));

			return new MockResponse('{"count":2}');
		});
		$runner = new BackgroundQueueApiClient(new BusinessApiClient($client, 'https://api.example.test', 'test'), 'trigger-secret');

		self::assertSame(2, $runner->run());
	}

	public function testRequiresTheTriggerCredential(): void
	{
		$runner = new BackgroundQueueApiClient(new BusinessApiClient(new MockHttpClient(), 'https://api.example.test', 'test'), '');
		$this->expectException(\LogicException::class);

		$runner->run();
	}

	/** @param array<array-key,mixed> $headers */
	private static function hasHeader(array $headers, string $name, string $value): bool
	{
		foreach ($headers as $key => $header) {
			if (is_string($key) && 0 === strcasecmp($name, $key) && $value === $header) {
				return true;
			}
			if (is_string($header) && 1 === preg_match('/^'.preg_quote($name, '/').':\s*'.preg_quote($value, '/').'$/iD', trim($header))) {
				return true;
			}
		}

		return false;
	}
}
