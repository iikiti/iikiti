<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\ContentOptimization;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Plugin\PluginManifest;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\Extension\ContentOptimization\Analytics\AnalyticsConnectorInterface;
use iikiti\Extension\ContentOptimization\Analytics\AnalyticsConnectorRegistry;
use iikiti\Extension\ContentOptimization\Analytics\BuiltInAnalyticsConnectorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Analytics/AnalyticsConnectorInterface.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Analytics/BuiltInAnalyticsConnectorInterface.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Analytics/AnalyticsConnectorRegistry.php';

final class AnalyticsConnectorRegistryTest extends TestCase
{
	public function testRejectsDuplicateConnectorKeys(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('already registered');

		new AnalyticsConnectorRegistry([
			new TestAnalyticsConnector(),
			new TestAnalyticsConnector(),
		]);
	}

	public function testRejectsMalformedNamespacedKeysAndNonAnalyticsConsent(): void
	{
		foreach ([
			new TestAnalyticsConnector(key: 'analytics'),
			new TestAnalyticsConnector(key: 'Plugin.bad'),
			new TestAnalyticsConnector(consentCategory: 'marketing'),
		] as $connector) {
			try {
				new AnalyticsConnectorRegistry([$connector]);
				self::fail('Expected connector registration to fail.');
			} catch (\InvalidArgumentException $exception) {
				self::assertNotSame('', $exception->getMessage());
			}
		}
	}

	public function testReturnsOnlyConnectorsWhosePluginIsEnabledForTheSite(): void
	{
		$registry = new AnalyticsConnectorRegistry([
			new TestAnalyticsConnector(key: 'active-plugin.metrics'),
			new TestAnalyticsConnector(key: 'inactive-plugin.metrics'),
		]);
		$site = new Site();
		$site->getConfiguration()->enablePlugin('active-plugin');

		self::assertSame(['active-plugin.metrics'], array_keys($registry->forSite($site)));
	}

	public function testRejectsMalformedClientDescriptors(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$registry = new AnalyticsConnectorRegistry([
			new TestAnalyticsConnector(descriptor: [
				'adapter' => 'attacker-adapter',
				'scriptUrl' => 'https://attacker.test/track.js',
				'parameters' => ['measurementId' => 'G-123'],
			]),
		]);
		$registry->clientDescriptor('vendor.metrics', ['measurementId' => 'G-123']);
	}

	public function testRejectsMarkupInDescriptorParametersAndWildcardScriptRules(): void
	{
		$descriptorRegistry = new AnalyticsConnectorRegistry([
			new TestAnalyticsConnector(descriptor: [
				'adapter' => 'vendor-metrics',
				'scriptUrl' => 'https://cdn.example.test/analytics.js',
				'parameters' => ['injected' => '<script>'],
			]),
		]);
		try {
			$descriptorRegistry->clientDescriptor('vendor.metrics', ['measurementId' => 'G-123']);
			self::fail('Expected markup in descriptor parameters to be rejected.');
		} catch (\InvalidArgumentException $exception) {
			self::assertStringContainsString('parameter', $exception->getMessage());
		}

		$this->expectException(\InvalidArgumentException::class);
		new AnalyticsConnectorRegistry([
			new TestAnalyticsConnector(scriptSrc: ['https://*.example.test/']),
		]);
	}

	public function testRejectsInsecureAndCredentialedCspSources(): void
	{
		foreach ([
			new TestAnalyticsConnector(scriptUrl: 'http://cdn.example.test/analytics.js'),
			new TestAnalyticsConnector(scriptSrc: ['https://user:secret@cdn.example.test/analytics.js']),
		] as $connector) {
			try {
				new AnalyticsConnectorRegistry([$connector]);
				self::fail('Expected unsafe HTTPS source to be rejected.');
			} catch (\InvalidArgumentException $exception) {
				self::assertNotSame('', $exception->getMessage());
			}
		}
	}

	public function testRejectsUnpinnedScriptSourcesAndSecretLikeSettingNames(): void
	{
		foreach ([
			new TestAnalyticsConnector(scriptSrc: ['https://cdn.example.test']),
			new TestAnalyticsConnector(scriptSrc: ['https://cdn.example.test/']),
		] as $connector) {
			try {
				new AnalyticsConnectorRegistry([$connector]);
				self::fail('Expected broad script source to be rejected.');
			} catch (\InvalidArgumentException $exception) {
				self::assertNotSame('', $exception->getMessage());
			}
		}

		$this->expectException(\InvalidArgumentException::class);
		new AnalyticsConnectorRegistry([
			new TestAnalyticsConnector(schema: ['apiKey' => ['type' => 'string', 'label' => 'API key']]),
		]);
	}

	public function testRejectsCspDirectiveInjectionDoubleSlashAndOverlyBroadWildcards(): void
	{
		foreach ([
			'https://cdn.example.test/path; connect-src https:',
			'https://cdn.example.test/path//loader.js',
			'https://*.com/analytics.js',
			'https://cdn.example.test:99999/analytics.js',
		] as $source) {
			try {
				new AnalyticsConnectorRegistry([
					new TestAnalyticsConnector(
						scriptUrl: 'https://cdn.example.test/analytics.js',
						scriptSrc: [$source],
					),
				]);
				self::fail('Expected unsafe CSP source to be rejected.');
			} catch (\InvalidArgumentException $exception) {
				self::assertNotSame('', $exception->getMessage());
			}
		}
	}

	public function testAcceptsExactAndSlashTerminatedPrefixScriptSources(): void
	{
		$exact = new TestAnalyticsConnector(
			key: 'vendor.exact',
			scriptUrl: 'https://cdn.example.test/analytics/loader.js',
			scriptSrc: ['https://cdn.example.test/analytics/loader.js'],
		);
		$prefix = new TestAnalyticsConnector(
			key: 'vendor.prefix',
			scriptUrl: 'https://cdn.example.test/analytics/v2/loader.js',
			scriptSrc: ['https://cdn.example.test/analytics/v2/'],
		);
		$registry = new AnalyticsConnectorRegistry([$exact, $prefix]);

		self::assertSame('https://cdn.example.test/analytics/loader.js', $registry->clientDescriptor('vendor.exact', ['measurementId' => 'G-123'])['scriptUrl']);
		self::assertSame('https://cdn.example.test/analytics/v2/loader.js', $registry->clientDescriptor('vendor.prefix', ['measurementId' => 'G-123'])['scriptUrl']);
	}

	public function testValidatesSchemaAndDoesNotAllowConfigToSetUrlOrMarkup(): void
	{
		$registry = new AnalyticsConnectorRegistry([new TestAnalyticsConnector()]);

		self::assertSame(['measurementId' => 'G-123'], $registry->validateConfig('vendor.metrics', ['measurementId' => 'G-123']));
		self::assertSame(
			['measurementId' => 'G-123', 'enabled' => false, 'region' => 'eu'],
			$registry->validateConfig('vendor.metrics', ['measurementId' => 'G-123', 'enabled' => false, 'region' => 'eu']),
		);
		foreach ([
			['measurementId' => 'G-123', 'scriptUrl' => 'https://evil.test/x.js'],
			['measurementId' => '<script>alert(1)</script>'],
			['measurementId' => 'G-123', 'enabled' => 'false'],
			['measurementId' => 'G-123', 'region' => 'apac'],
		] as $config) {
			try {
				$registry->validateConfig('vendor.metrics', $config);
				self::fail('Expected connector config validation to fail.');
			} catch (\InvalidArgumentException) {
			}
		}
	}

	public function testExposesRegisteredConnectorsSchemasAndSupportsPartialSettings(): void
	{
		$registry = new AnalyticsConnectorRegistry([new TestAnalyticsConnector()]);

		self::assertSame(['vendor.metrics'], array_keys($registry->all()));
		self::assertSame('Measurement ID', $registry->settingSchema('vendor.metrics')['measurementId']['label']);
		self::assertSame('https://analytics.example.test/', $registry->dashboardUrl('vendor.metrics'));
		self::assertSame(['region' => 'eu'], $registry->validateConfig('vendor.metrics', ['region' => 'eu'], true));
	}

	public function testRejectsPrototypePropertyNamesInSettingSchema(): void
	{
		$rejected = [];
		foreach (['__proto__', 'constructor', 'prototype', 'toString'] as $name) {
			try {
				new AnalyticsConnectorRegistry([
					new TestAnalyticsConnector(schema: [$name => ['type' => 'string', 'label' => 'Field']]),
				]);
				self::fail('Expected JS prototype property name to be rejected: '.$name);
			} catch (\InvalidArgumentException) {
				$rejected[] = $name;
			}
		}
		self::assertCount(4, $rejected);
	}

	public function testSkipsAndLogsMalformedOrSpoofedThirdPartyConnectorsWhenPluginRegistryIsPresent(): void
	{
		$manifest = PluginManifest::fromArray([
			'slug' => 'vendor',
			'name' => 'Vendor Analytics',
			'version' => '1.0.0',
			'iikiti_version' => '^1.0',
			'namespace' => __NAMESPACE__.'\\',
			'bundle_class' => __NAMESPACE__.'\\TestPluginBundle',
		]);
		$pluginRegistry = $this->createStub(PluginRegistry::class);
		$pluginRegistry->method('getManifests')->willReturn(['vendor' => $manifest]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::atLeast(3))->method('warning');

		$registry = new AnalyticsConnectorRegistry(
			[
				new TestAnalyticsConnector(key: 'vendor.valid'),
				new TestAnalyticsConnector(key: 'other-plugin.spoof'),
				new TestAnalyticsConnector(key: 'iikiti-content-optimization.spoof'),
				new TestAnalyticsConnector(key: 'vendor.bad', scriptUrl: 'http://insecure.example.test/a.js'),
			],
			$pluginRegistry,
			$logger,
		);

		self::assertSame(['vendor.valid'], array_keys($registry->all()));
	}
}

final class TestAnalyticsConnector implements AnalyticsConnectorInterface
{
	/**
	 * @param array<string,mixed>|null               $descriptor
	 * @param list<string>|null                      $scriptSrc
	 * @param array<string,array<string,mixed>>|null $schema
	 */
	public function __construct(
		private string $key = 'vendor.metrics',
		private string $consentCategory = 'analytics',
		private string $scriptUrl = 'https://cdn.example.test/analytics.js',
		private ?array $descriptor = null,
		private ?array $scriptSrc = null,
		private ?array $schema = null,
	) {
	}

	public function key(): string
	{
		return $this->key;
	}

	public function label(): string
	{
		return 'Metrics';
	}

	public function dashboardUrl(): string
	{
		return 'https://analytics.example.test/';
	}

	public function consentCategory(): string
	{
		return $this->consentCategory;
	}

	public function settingSchema(): array
	{
		return $this->schema ?? [
			'measurementId' => ['type' => 'string', 'label' => 'Measurement ID', 'maxLength' => 32, 'pattern' => '/^G-[A-Z0-9]+$/'],
			'enabled' => ['type' => 'boolean', 'label' => 'Enabled', 'required' => false],
			'region' => ['type' => 'enum', 'label' => 'Region', 'required' => false, 'options' => ['eu' => 'Europe', 'us' => 'United States']],
		];
	}

	/** @param array<string,scalar> $config */
	public function validateConfig(array $config, bool $partial = false): void
	{
	}

	public function adapterIdentifier(): string
	{
		return 'vendor-metrics';
	}

	public function scriptUrl(): string
	{
		return $this->scriptUrl;
	}

	public function buildClientDescriptor(array $validatedConfig): array
	{
		return $this->descriptor ?? [
			'adapter' => $this->adapterIdentifier(),
			'scriptUrl' => $this->scriptUrl(),
			'parameters' => $validatedConfig,
		];
	}

	public function cspRequirements(): array
	{
		return [
			'scriptSrc' => $this->scriptSrc ?? ['https://cdn.example.test/analytics.js'],
			'connectSrc' => ['https://collect.example.test'],
			'imgSrc' => [],
		];
	}
}
