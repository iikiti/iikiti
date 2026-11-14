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
		$providerRequests = [];
		$http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$providerRequests): MockResponse {
			self::assertSame('GET', $method);
			self::assertTrue(self::hasHeader($options['headers'], 'X-SITE-HOST', 'www.example.test'));
			self::assertStringStartsWith('guest2.', (string) self::header($options['headers'], 'X-AUTH-TOKEN'));
			$path = (string) parse_url($url, PHP_URL_PATH);
			if (str_ends_with($path, '/manifest')) {
				return new MockResponse(json_encode([
					'html' => '<!DOCTYPE html><html><head><template data-iikiti-fragment="custom-css"></template></head><body><main><template data-iikiti-region-slot="'.str_repeat('a', 32).'"></template><template data-iikiti-provider-slot="'.str_repeat('b', 32).'"></template><template data-iikiti-provider-slot="'.str_repeat('c', 32).'"></template><template data-iikiti-provider-slot="'.str_repeat('d', 32).'"></template></main></body></html>',
					'styles' => [],
					'editor_mode' => false,
					'revision' => str_repeat('a', 64),
					'inline_asset_minification' => ['css_minify' => true, 'js_minify' => true, 'js_uglify' => false],
					'fragments' => [
						['kind' => 'region', 'id' => 'main', 'role' => 'main', 'slot' => str_repeat('a', 32)],
						['kind' => 'provider', 'id' => 'sample-plugin.first', 'role' => 'provider', 'slot' => str_repeat('b', 32), 'depth' => 0, 'key' => 'first', 'dependsOn' => [], 'params' => ['value' => 'one']],
						['kind' => 'provider', 'id' => 'sample-plugin.second', 'role' => 'provider', 'slot' => str_repeat('c', 32), 'depth' => 0, 'key' => 'second', 'dependsOn' => ['first'], 'params' => ['value' => 'two']],
						['kind' => 'provider', 'id' => 'sample-plugin.third', 'role' => 'provider', 'slot' => str_repeat('d', 32), 'depth' => 0, 'key' => 'third', 'dependsOn' => [], 'params' => ['value' => 'three']],
					],
				], JSON_THROW_ON_ERROR));
			}
			if (str_contains($path, '/providers/')) {
				$provider = substr($path, (int) strrpos($path, '/') + 1);
				$providerRequests[] = $provider;
				parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
				self::assertArrayHasKey('params', $query);

				return new MockResponse(json_encode([
					'html' => '<aside>'.$provider.'</aside>',
					'styles' => [['id' => 'sample-plugin.'.$provider, 'css' => '.'.$provider.'{}']],
				], JSON_THROW_ON_ERROR));
			}

			self::assertStringEndsWith('/api/public/render/pages/about/fragments/region', $path);
			parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
			self::assertSame('@main', $query['_iikiti_fragment_id'] ?? null);

			return new MockResponse(json_encode([
				'found' => true,
				'id' => 'main',
				'revision' => str_repeat('a', 64),
				'html' => '<p>served by API</p>',
				'styles' => [],
			], JSON_THROW_ON_ERROR));
		});
		$client = static::createClient();
		$client->catchExceptions(false);
		$container = static::getContainer();
		self::assertSame('consumer', $container->getParameter('application_role'));
		$container->set(HttpClientInterface::class, $http);

		$client->request('GET', '/about', server: ['HTTP_HOST' => 'www.example.test']);

		self::assertResponseIsSuccessful();
		self::assertSelectorTextContains('main', 'served by API');
		self::assertSelectorTextContains('main', 'sample-plugin.first');
		self::assertSelectorTextContains('main', 'sample-plugin.second');
		self::assertSelectorTextContains('main', 'sample-plugin.third');
		self::assertLessThan(array_search('sample-plugin.second', $providerRequests, true), array_search('sample-plugin.first', $providerRequests, true));
		self::assertSame(5, $http->getRequestsCount());
	}

	public function testSameOriginFragmentRequestRelaysWithoutDatabaseAccess(): void
	{
		$this->setEnvironment('APPLICATION_ROLE', 'consumer');
		$this->setEnvironment('DATABASE_URL', '');
		$http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('GET', $method);
			self::assertSame('/api/public/render/providers/sample-plugin.card', parse_url($url, PHP_URL_PATH));
			self::assertTrue(self::hasHeader($options['headers'], 'X-SITE-HOST', 'www.example.test'));
			self::assertStringStartsWith('guest2.', (string) self::header($options['headers'], 'X-AUTH-TOKEN'));
			parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
			self::assertSame(['title' => 'Lazy'], json_decode($query['params'], true, 16, JSON_THROW_ON_ERROR));

			return new MockResponse(json_encode([
				'html' => '<aside>Lazy</aside>',
				'styles' => [['id' => 'sample-plugin.card', 'css' => '.card{}']],
			], JSON_THROW_ON_ERROR));
		});
		$client = static::createClient();
		$client->catchExceptions(false);
		static::getContainer()->set(HttpClientInterface::class, $http);
		$params = rawurlencode(json_encode(['title' => 'Lazy'], JSON_THROW_ON_ERROR));

		$client->request('GET', '/_iikiti/fragments/sample-plugin.card?params='.$params, server: ['HTTP_HOST' => 'www.example.test']);

		self::assertResponseIsSuccessful();
		self::assertSame('<aside>Lazy</aside>', json_decode($client->getResponse()->getContent(), true, 16, JSON_THROW_ON_ERROR)['html']);
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
