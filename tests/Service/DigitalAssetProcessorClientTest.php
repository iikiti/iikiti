<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Service\DigitalAssetProcessorClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;

final class DigitalAssetProcessorClientTest extends TestCase
{
	public function testWorkerCallsOnlyItsAssetProcessApiAction(): void
	{
		$client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('POST', $method);
			self::assertSame('https://api.example.test/api/internal/digital-assets/42/process', $url);
			self::assertTrue(self::hasHeader($options['headers'], 'X-ASSET-PROCESS-TRIGGER', 'asset-trigger'));
			self::assertSame(120.0, $options['timeout']);

			return new MockResponse('{"processed":true}');
		});
		$processor = new DigitalAssetProcessorClient(
			new BusinessApiClient($client, 'https://api.example.test', 'test'),
			'asset-trigger',
			(new \ReflectionClass(\iikiti\CMS\Service\DigitalAssetWorkflowManager::class))->newInstanceWithoutConstructor(),
			new RequestStack(),
		);

		$processor->process('42');
	}

	public function testRejectsInvalidIdsOrMissingCredentials(): void
	{
		$processor = new DigitalAssetProcessorClient(
			new BusinessApiClient(new MockHttpClient(), 'https://api.example.test', 'test'),
			'',
			(new \ReflectionClass(\iikiti\CMS\Service\DigitalAssetWorkflowManager::class))->newInstanceWithoutConstructor(),
			new RequestStack(),
		);
		$this->expectException(\LogicException::class);

		$processor->process('../42');
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
