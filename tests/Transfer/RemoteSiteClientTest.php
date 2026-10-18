<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Transfer;

use iikiti\CMS\Transfer\RemoteSiteClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class RemoteSiteClientTest extends TestCase
{
	public function testNormalizesHttpsSourceOriginAndProxyPath(): void
	{
		$client = new RemoteSiteClient(new MockHttpClient());

		self::assertSame('https://cms.example.org/cms', $client->normalizeBaseUrl('https://CMS.example.org/cms/'));
	}

	#[DataProvider('unsafeUrls')]
	public function testRejectsNonHttpsAndCredentialBearingUrls(string $url): void
	{
		$client = new RemoteSiteClient(new MockHttpClient());
		$this->expectException(\InvalidArgumentException::class);
		$client->normalizeBaseUrl($url);
	}

	/** @return iterable<string,array{string}> */
	public static function unsafeUrls(): iterable
	{
		yield 'non-https' => ['http://cms.example.org'];
		yield 'embedded credentials' => ['https://user:pass@cms.example.org'];
		yield 'query string' => ['https://cms.example.org?token=secret'];
	}
}
