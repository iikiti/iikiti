<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Batch;

use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Batch\BatchScheduleRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;

final class BatchScheduleRunnerTest extends TestCase
{
	public function testCronCallsOnlyTheDueScheduleApiAction(): void
	{
		$client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('POST', $method);
			self::assertSame('https://api.example.test/api/internal/schedules/run-due', $url);
			self::assertSame(['limit' => 17], json_decode((string) $options['body'], true));
			self::assertTrue(self::hasHeader($options['headers'], 'X-SCHEDULE-TRIGGER', 'trigger-secret'));

			return new MockResponse('{"count":3}');
		});
		$runner = new BatchScheduleRunner(
			new BusinessApiClient($client, 'https://api.example.test', 'test'),
			'trigger-secret',
			(new \ReflectionClass(\iikiti\CMS\Batch\BatchScheduleManager::class))->newInstanceWithoutConstructor(),
			new RequestStack(),
		);

		self::assertSame(3, $runner->runDue(17));
	}

	public function testCronCannotRunWithoutItsTriggerCredential(): void
	{
		$runner = new BatchScheduleRunner(
			new BusinessApiClient(new MockHttpClient(), 'https://api.example.test', 'test'),
			'',
			(new \ReflectionClass(\iikiti\CMS\Batch\BatchScheduleManager::class))->newInstanceWithoutConstructor(),
			new RequestStack(),
		);
		$this->expectException(\LogicException::class);

		$runner->runDue();
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
