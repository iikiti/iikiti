<?php

namespace iikiti\CMS\Tests\Command\Search;

use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Command\Search\ConfigInitCommand;
use iikiti\CMS\Command\Search\ConfigListCommand;
use iikiti\CMS\Command\Search\IndexCreateCommand;
use iikiti\CMS\Command\Search\IndexDropCommand;
use iikiti\CMS\Command\Search\IndexRebuildCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearchCommandsTest extends TestCase
{
	public function testConfigListUsesTheUserCredentialAndOptionalSiteHost(): void
	{
		$requests = [];
		$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
			$requests[] = [$method, $url, $options];

			return new MockResponse('{"hydra:member":[{"id":4,"slug":"admin","name":"Admin","type":"admin","engine":"postgresql","isEnabled":true,"systemLocked":true}]}');
		});
		$command = new ConfigListCommand(new BusinessApiClient($client, 'https://api.example.test', 'test'), 'user-token');
		$tester = new CommandTester($command);

		self::assertSame(0, $tester->execute(['--site-host' => 'shop.example.test']));
		self::assertStringContainsString('Administration Search', $tester->getDisplay());
		self::assertSame('GET', $requests[0][0]);
		self::assertSame('https://api.example.test/api/admin/search/indexes', $requests[0][1]);
		self::assertTrue(self::hasHeader($requests[0][2]['headers'], 'X-AUTH-TOKEN', 'user-token'));
		self::assertTrue(self::hasHeader($requests[0][2]['headers'], 'X-Site-Host', 'shop.example.test'));
	}

	public function testRebuildAllForwardsSiteIdsToTheBusinessAction(): void
	{
		$requests = [];
		$responses = ['{"status":"success","result":{"count":2}}'];
		$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
			$requests[] = [$method, $url, $options];

			return new MockResponse(array_shift($responses));
		});
		$command = new IndexRebuildCommand(new BusinessApiClient($client, 'https://api.example.test', 'test'), 'user-token');
		$tester = new CommandTester($command);

		self::assertSame(0, $tester->execute(['--site' => ['12'], '--site-host' => 'shop.example.test']));
		self::assertStringContainsString('Rebuilt 2 search index(es).', $tester->getDisplay());
		self::assertSame('/api/admin/search/rebuild-all', parse_url($requests[0][1], PHP_URL_PATH));
		self::assertTrue(self::hasHeader($requests[0][2]['headers'], 'X-AUTH-TOKEN', 'user-token'));
		self::assertTrue(self::hasHeader($requests[0][2]['headers'], 'X-Site-Host', 'shop.example.test'));
		self::assertSame(['siteIds' => ['12']], json_decode($requests[0][2]['body'], true, flags: JSON_THROW_ON_ERROR));
	}

	public function testDropCancellationDoesNotCallTheMutationEndpoint(): void
	{
		$requests = [];
		$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
			$requests[] = [$method, $url];

			return new MockResponse('{"hydra:member":[{"id":4,"slug":"custom","name":"Custom","type":"custom","engine":"postgresql","isEnabled":true,"systemLocked":false}]}');
		});
		$command = new IndexDropCommand(new BusinessApiClient($client, 'https://api.example.test', 'test'), 'user-token');
		$tester = new CommandTester($command);
		$tester->setInputs(['no']);

		self::assertSame(0, $tester->execute(['slug' => 'custom']));
		self::assertStringContainsString('Operation cancelled.', $tester->getDisplay());
		self::assertCount(1, $requests);
		self::assertSame('GET', $requests[0][0]);
	}

	public function testCreateResolvesTheIndexThroughTheApiAndPreservesItsErrorMessage(): void
	{
		$requests = [];
		$responses = [
			'{"hydra:member":[{"id":9,"slug":"frontend","name":"Front-end Search","engine":"postgresql","isEnabled":true}]}',
			'{"status":"error","message":"Failed to create index: database unavailable"}',
		];
		$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
			$requests[] = [$method, $url];

			return new MockResponse(array_shift($responses));
		});
		$command = new IndexCreateCommand(new BusinessApiClient($client, 'https://api.example.test', 'test'), 'user-token');
		$tester = new CommandTester($command);

		self::assertSame(1, $tester->execute(['slug' => 'frontend']));
		self::assertSame([
			['GET', 'https://api.example.test/api/admin/search/indexes'],
			['POST', 'https://api.example.test/api/admin/search/indexes/9/create'],
		], $requests);
		self::assertSame(1, substr_count($tester->getDisplay(), 'Failed to create index:'));
		self::assertStringContainsString('database unavailable', $tester->getDisplay());
	}

	public function testConfigInitUsesItsDedicatedBusinessAction(): void
	{
		$requests = [];
		$responses = ['{"hydra:member":[]}', '{"status":"success"}'];
		$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
			$requests[] = [$method, $url];

			return new MockResponse(array_shift($responses));
		});
		$command = new ConfigInitCommand(new BusinessApiClient($client, 'https://api.example.test', 'test'), 'user-token');
		$tester = new CommandTester($command);

		self::assertSame(0, $tester->execute([]));
		self::assertSame([
			['GET', 'https://api.example.test/api/admin/search/indexes'],
			['POST', 'https://api.example.test/api/admin/search/config/init'],
		], $requests);
		self::assertStringContainsString('Default search configurations created successfully.', $tester->getDisplay());
	}

	public function testConfigInitCancellationDoesNotCallItsMutationEndpoint(): void
	{
		$requests = [];
		$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
			$requests[] = [$method, $url];

			return new MockResponse('{"hydra:member":[{"slug":"frontend","systemLocked":false},{"slug":"admin","systemLocked":true}]}');
		});
		$command = new ConfigInitCommand(new BusinessApiClient($client, 'https://api.example.test', 'test'), 'user-token');
		$tester = new CommandTester($command);
		$tester->setInputs(['no']);

		self::assertSame(0, $tester->execute([]));
		self::assertCount(1, $requests);
		self::assertStringNotContainsString('config/init', $tester->getDisplay());
	}

	/** @param array<array-key,mixed> $headers */
	private static function hasHeader(array $headers, string $name, string $value): bool
	{
		foreach ($headers as $headerName => $headerValue) {
			if (is_string($headerName) && 0 === strcasecmp($name, $headerName) && $value === $headerValue) {
				return true;
			}
			if (is_string($headerValue) && 1 === preg_match('/^'.preg_quote($name, '/').':\\s*'.preg_quote($value, '/').'$/iD', trim($headerValue))) {
				return true;
			}
		}

		return false;
	}
}
