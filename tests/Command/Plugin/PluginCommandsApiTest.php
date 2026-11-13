<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Command\Plugin;

use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Command\Plugin\ConfigureCommand;
use iikiti\CMS\Command\Plugin\EnableCommand;
use iikiti\CMS\Command\Plugin\InstallCommand;
use iikiti\CMS\Command\Plugin\ListCommand;
use iikiti\CMS\Command\Plugin\UpdateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PluginCommandsApiTest extends TestCase
{
	private string|false $originalProcessToken;
	private bool $hadEnvironmentToken;
	private mixed $originalEnvironmentToken;
	private bool $hadServerToken;
	private mixed $originalServerToken;

	protected function setUp(): void
	{
		$this->originalProcessToken = getenv('INTERNAL_API_TOKEN');
		$this->hadEnvironmentToken = array_key_exists('INTERNAL_API_TOKEN', $_ENV);
		$this->originalEnvironmentToken = $_ENV['INTERNAL_API_TOKEN'] ?? null;
		$this->hadServerToken = array_key_exists('INTERNAL_API_TOKEN', $_SERVER);
		$this->originalServerToken = $_SERVER['INTERNAL_API_TOKEN'] ?? null;
		parent::setUp();
	}

	protected function tearDown(): void
	{
		if (false === $this->originalProcessToken) {
			putenv('INTERNAL_API_TOKEN');
		} else {
			putenv('INTERNAL_API_TOKEN='.$this->originalProcessToken);
		}
		if ($this->hadEnvironmentToken) {
			$_ENV['INTERNAL_API_TOKEN'] = $this->originalEnvironmentToken;
		} else {
			unset($_ENV['INTERNAL_API_TOKEN']);
		}
		if ($this->hadServerToken) {
			$_SERVER['INTERNAL_API_TOKEN'] = $this->originalServerToken;
		} else {
			unset($_SERVER['INTERNAL_API_TOKEN']);
		}
		parent::tearDown();
	}

	public function testListUsesAdminApiAndAppliesActiveSiteFilter(): void
	{
		putenv('INTERNAL_API_TOKEN=v2_user-bound-admin-token');
		$api = $this->api(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('GET', $method);
			self::assertSame('https://api.example.test/api/admin/plugins', $url);
			self::assertTrue(self::hasHeader($options['headers'], 'X-AUTH-TOKEN', 'v2_user-bound-admin-token'));
			self::assertTrue(self::hasHeader($options['headers'], 'X-SITE-HOST', 'admin.example.test'));

			return new MockResponse(json_encode(['hydra:member' => [[
				'slug' => 'acme-blog',
				'version' => '1.2.0',
				'edition' => 'standard',
				'source' => 'store',
				'state' => 'published',
				'active' => true,
				'activeSites' => ['12', '15'],
			]]], JSON_THROW_ON_ERROR));
		});

		$tester = new CommandTester(new ListCommand($api));
		self::assertSame(0, $tester->execute(['--site' => ['15'], '--site-host' => 'admin.example.test']));
		self::assertStringContainsString('15', $tester->getDisplay());
		self::assertStringNotContainsString('12', $tester->getDisplay());
		self::assertStringContainsString('1 plugin(s) installed, 1 loaded', $tester->getDisplay());
	}

	public function testInstallForwardsActivationAndSiteScopeToBusinessApi(): void
	{
		putenv('INTERNAL_API_TOKEN=v2_user-bound-admin-token');
		$api = $this->api(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('POST', $method);
			self::assertSame('https://api.example.test/api/admin/plugins/install', $url);
			self::assertSame([
				'slug' => 'acme-blog',
				'version' => null,
				'sites' => [],
				'storeUrl' => null,
				'activate' => false,
				'forceConflicts' => false,
			], self::decodeBody($options));
			self::assertTrue(self::hasHeader($options['headers'], 'X-AUTH-TOKEN', 'v2_user-bound-admin-token'));

			return new MockResponse('{"success":true,"message":"Installed acme-blog 1.2.0."}');
		});

		$tester = new CommandTester(new InstallCommand($api));
		self::assertSame(0, $tester->execute(['slug' => 'acme-blog', '--no-activate' => true]));
		self::assertStringContainsString('Installed acme-blog 1.2.0.', $tester->getDisplay());
	}

	public function testEnableForwardsExplicitConflictOverride(): void
	{
		putenv('INTERNAL_API_TOKEN=v2_user-bound-admin-token');
		$api = $this->api(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('POST', $method);
			self::assertSame('https://api.example.test/api/admin/plugins/enable', $url);
			self::assertSame([
				'slug' => 'acme-security',
				'sites' => ['12'],
				'forceConflicts' => true,
			], self::decodeBody($options));

			return new MockResponse('{"success":true,"message":"Enabled acme-security."}');
		});

		$tester = new CommandTester(new EnableCommand($api));
		self::assertSame(0, $tester->execute([
			'slug' => 'acme-security',
			'--site' => ['12'],
			'--force-conflicts' => true,
		]));
	}

	public function testInstallForwardsExplicitConflictOverride(): void
	{
		putenv('INTERNAL_API_TOKEN=v2_user-bound-admin-token');
		$api = $this->api(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('POST', $method);
			self::assertSame('https://api.example.test/api/admin/plugins/install', $url);
			self::assertSame([
				'slug' => 'acme-blog',
				'version' => null,
				'sites' => [],
				'storeUrl' => null,
				'activate' => true,
				'forceConflicts' => true,
			], self::decodeBody($options));

			return new MockResponse('{"success":true,"message":"Installed acme-blog."}');
		});

		$tester = new CommandTester(new InstallCommand($api));
		self::assertSame(0, $tester->execute(['slug' => 'acme-blog', '--force-conflicts' => true]));
	}

	public function testUpdateForwardsExplicitConflictOverride(): void
	{
		putenv('INTERNAL_API_TOKEN=v2_user-bound-admin-token');
		$request = 0;
		$api = $this->api(static function (string $method, string $url, array $options) use (&$request): MockResponse {
			++$request;
			if (1 === $request) {
				self::assertSame('GET', $method);
				self::assertSame('https://api.example.test/api/admin/plugins', $url);

				return new MockResponse(json_encode(['hydra:member' => [[
					'slug' => 'acme-blog',
					'version' => '1.0.0',
				]]], JSON_THROW_ON_ERROR));
			}

			self::assertSame('POST', $method);
			self::assertSame('https://api.example.test/api/admin/plugins/update', $url);
			self::assertSame([
				'slug' => 'acme-blog',
				'version' => null,
				'storeUrl' => null,
				'dryRun' => false,
				'forceConflicts' => true,
			], self::decodeBody($options));

			return new MockResponse('{"success":true,"message":"Updated.","result":{"version":"1.1.0"}}');
		});

		$tester = new CommandTester(new UpdateCommand($api));
		self::assertSame(0, $tester->execute(['slug' => 'acme-blog', '--force-conflicts' => true]));
	}

	public function testConfigureForwardsOptionalSiteHostContext(): void
	{
		putenv('INTERNAL_API_TOKEN=v2_user-bound-admin-token');
		$api = $this->api(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('POST', $method);
			self::assertSame('https://api.example.test/api/admin/plugins/configure', $url);
			self::assertSame(['slug' => 'acme-blog', 'siteId' => '15', 'set' => [], 'unset' => []], self::decodeBody($options));
			self::assertTrue(self::hasHeader($options['headers'], 'X-SITE-HOST', 'site.example.test'));

			return new MockResponse('{"success":true,"configuration":{"enabled":true}}');
		});

		$tester = new CommandTester(new ConfigureCommand($api));
		self::assertSame(0, $tester->execute(['slug' => 'acme-blog', '--site' => '15', '--site-host' => 'site.example.test']));
		self::assertStringContainsString('"enabled": true', $tester->getDisplay());
	}

	public function testMissingCredentialFailsClearlyBeforeMakingRequest(): void
	{
		putenv('INTERNAL_API_TOKEN');
		unset($_ENV['INTERNAL_API_TOKEN'], $_SERVER['INTERNAL_API_TOKEN']);
		$api = $this->api(static function (): MockResponse {
			self::fail('The API must not be called without a configured credential.');
		});

		$tester = new CommandTester(new ListCommand($api));
		self::assertSame(1, $tester->execute([]));
		self::assertStringContainsString('INTERNAL_API_TOKEN must be set', $tester->getDisplay());
	}

	private function api(callable $responseFactory): BusinessApiClient
	{
		return new BusinessApiClient(new MockHttpClient($responseFactory), 'https://api.example.test', 'test');
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

	/** @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private static function decodeBody(array $options): array
	{
		$body = $options['body'] ?? null;
		self::assertIsString($body);

		return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
	}
}
