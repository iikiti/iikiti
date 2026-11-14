<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Service\RemoteResponseHeaderResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RemoteResponseHeaderResolverTest extends TestCase
{
	public function testSendsOriginalContextWithoutCredentials(): void
	{
		$client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
			self::assertSame('POST', $method);
			self::assertSame('http://api.internal/api/internal/response-headers/resolve', $url);
			$data = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
			$payload = $data['payload'];
			self::assertSame(hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), 'shared-secret'), $data['signature']);
			self::assertSame(404, $payload['status']);
			self::assertSame('/missing', $payload['path']);
			self::assertSame('POST', $payload['method']);
			self::assertSame('example.test', $payload['host']);
			self::assertSame('https', $payload['scheme']);
			self::assertSame('visible', $payload['headers']['x-device']);
			self::assertArrayNotHasKey('x-example', $payload['headers']);
			self::assertArrayNotHasKey('authorization', $payload['headers']);
			self::assertArrayNotHasKey('cookie', $payload['headers']);
			self::assertArrayNotHasKey('x-auth-token', $payload['headers']);
			self::assertArrayNotHasKey('x-auth', $payload['headers']);
			self::assertStringNotContainsString('private', $options['body']);

			return new MockResponse('{"rules":{},"siteMode":"default","vary":["x-example"]}', ['http_code' => 200]);
		});
		$resolver = new RemoteResponseHeaderResolver(new BusinessApiClient($client, 'http://api.internal', 'test'), 'shared-secret');
		$request = Request::create('https://example.test/missing', 'POST', server: ['HTTP_AUTHORIZATION' => 'private', 'HTTP_COOKIE' => 'private', 'HTTP_X_AUTH_TOKEN' => 'private', 'HTTP_X_AUTH' => 'private', 'HTTP_X_DEVICE' => 'visible', 'HTTP_X_EXAMPLE' => 'hidden']);
		self::assertSame(['rules' => [], 'siteMode' => 'default', 'vary' => ['x-example']], $resolver->resolve($request, new Response('', 404)));
	}

	public function testRejectsSelfCallingApi(): void
	{
		$resolver = new RemoteResponseHeaderResolver(new BusinessApiClient(new MockHttpClient(), 'http://example.test', 'test'), 'shared-secret');
		$this->expectExceptionMessage('must target a separate API runtime');
		$resolver->resolve(Request::create('http://example.test/'), new Response());
	}
}
