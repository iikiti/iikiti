<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ConsumerRuntimeDatabaseBoundaryTest extends WebTestCase
{
	/** @var array<string,array{env:string|false,server:mixed,hasServer:bool,environment:mixed,hasEnvironment:bool}> */
	private array $originalEnvironment = [];

	protected function tearDown(): void
	{
		foreach ($this->originalEnvironment as $name => $state) {
			if (false === $state['env']) {
				putenv($name);
			} else {
				putenv($name.'='.$state['env']);
			}
			if ($state['hasServer']) {
				$_SERVER[$name] = $state['server'];
			} else {
				unset($_SERVER[$name]);
			}
			if ($state['hasEnvironment']) {
				$_ENV[$name] = $state['environment'];
			} else {
				unset($_ENV[$name]);
			}
		}
		parent::tearDown();
	}

	public function testPublicPageProxyWorksWithoutLocalDatabaseCredentials(): void
	{
		$this->setEnvironment('APPLICATION_ROLE', 'consumer');
		$this->setEnvironment('DATABASE_URL', '');
		$http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('GET', $method);
			self::assertStringEndsWith('/api/public/render/pages/about', $url);
			self::assertTrue(self::hasHeader($options['headers'], 'X-SITE-HOST', 'www.example.test'));
			self::assertStringStartsWith('guest1.', (string) self::header($options['headers'], 'X-AUTH-TOKEN'));

			return new MockResponse('{"html":"<main>served by API</main>"}');
		});
		$client = static::createClient();
		$client->catchExceptions(false);
		$container = static::getContainer();
		self::assertSame('consumer', $container->getParameter('application_role'));
		$container->set(HttpClientInterface::class, $http);

		$client->request('GET', '/about', server: ['HTTP_HOST' => 'www.example.test']);

		self::assertResponseIsSuccessful();
		self::assertSelectorTextContains('main', 'served by API');
		self::assertSame(1, $http->getRequestsCount());
	}

	private function setEnvironment(string $name, string $value): void
	{
		if (!isset($this->originalEnvironment[$name])) {
			$this->originalEnvironment[$name] = [
				'env' => getenv($name),
				'server' => $_SERVER[$name] ?? null,
				'hasServer' => array_key_exists($name, $_SERVER),
				'environment' => $_ENV[$name] ?? null,
				'hasEnvironment' => array_key_exists($name, $_ENV),
			];
		}
		putenv($name.'='.$value);
		$_SERVER[$name] = $value;
		$_ENV[$name] = $value;
	}

	/** @param array<array-key,mixed> $headers */
	private static function hasHeader(array $headers, string $name, string $value): bool
	{
		return self::header($headers, $name) === $value;
	}

	/** @param array<array-key,mixed> $headers */
	private static function header(array $headers, string $name): ?string
	{
		foreach ($headers as $key => $value) {
			if (is_string($key) && 0 === strcasecmp($name, $key) && is_string($value)) {
				return $value;
			}
			if (is_string($value) && 1 === preg_match('/^'.preg_quote($name, '/').':\s*(.*?)$/iD', trim($value), $matches)) {
				return $matches[1];
			}
		}

		return null;
	}
}
