<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\ContentOptimization;

use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\Extension\ContentOptimization\Analytics\AnalyticsConnectorInterface;
use iikiti\Extension\ContentOptimization\Analytics\AnalyticsConnectorRegistry;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/ContentOptimizationSettings.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Analytics/AnalyticsConnectorInterface.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Analytics/AnalyticsConnectorRegistry.php';

final class ContentOptimizationSettingsTest extends TestCase
{
	private ContentOptimizationSettings $settings;

	protected function setUp(): void
	{
		$this->settings = new ContentOptimizationSettings();
	}

	public function testUsesContractDefaultsAndRecursivelyResolvesPartialSiteOverrides(): void
	{
		$defaults = $this->settings->defaults();
		$overrides = $this->settings->validateSiteSettings($this->object([
			'version' => 1,
			'overrides' => ['analytics' => ['ga4' => ['enabled' => true]]],
		]))['overrides'];
		$resolved = $this->settings->resolve($defaults, $overrides);

		self::assertSame('{pageTitle} | {siteName}', $defaults['seo']['titleTemplate']);
		self::assertSame([], $defaults['analytics']['connectors']);
		self::assertTrue($resolved['effective']['analytics']['ga4']['enabled']);
		self::assertSame('', $resolved['effective']['analytics']['ga4']['measurementId']);
		self::assertSame('site', $resolved['sources']['analytics']['ga4']['enabled']);
		self::assertSame('application', $resolved['sources']['analytics']['ga4']['measurementId']);
		self::assertSame('application', $resolved['sources']['seo']['titleTemplate']);
	}

	public function testResolvesDynamicConnectorOverrideOnlyKeysAndTheirSources(): void
	{
		$resolved = $this->settings->resolve($this->settings->defaults(), [
			'analytics' => [
				'connectors' => [
					'vendor' => ['metrics' => ['enabled' => true, 'settings' => ['region' => 'eu']]],
				],
			],
		]);

		self::assertSame(true, $resolved['effective']['analytics']['connectors']['vendor']['metrics']['enabled']);
		self::assertSame('eu', $resolved['effective']['analytics']['connectors']['vendor']['metrics']['settings']['region']);
		self::assertSame('site', $resolved['sources']['analytics']['connectors']['vendor']['metrics']['settings']['region']);
	}

	public function testValidatesApplicationConnectorsAndPartialSiteSettingsAgainstActivePlugins(): void
	{
		$settings = new ContentOptimizationSettings(new AnalyticsConnectorRegistry([
			new SettingsAnalyticsConnector('vendor.metrics'),
			new SettingsAnalyticsConnector('inactive.metrics'),
		]));
		$site = new Site();
		$site->getConfiguration()->enablePlugin('vendor');
		$defaults = $settings->defaults();
		$defaults['analytics']['connectors'] = [
			'vendor' => ['metrics' => ['enabled' => true, 'settings' => ['measurementId' => 'G-1234']]],
			'inactive' => ['metrics' => ['enabled' => false, 'settings' => ['measurementId' => 'G-5678']]],
		];
		$application = $settings->validateApplicationSettings($this->object($defaults));
		self::assertSame($defaults['analytics']['connectors'], $application['analytics']['connectors']);
		$availableConnectors = $settings->availableConnectors();
		self::assertSame(['key', 'label', 'dashboardUrl', 'settingSchema', 'cspRequirements'], array_keys($availableConnectors[0]));
		self::assertNull($availableConnectors[0]['dashboardUrl']);

		$siteInput = $this->object([
			'version' => 1,
			'overrides' => ['analytics' => ['connectors' => [
				'vendor' => ['metrics' => ['settings' => ['region' => 'eu']]],
			]]],
		]);
		$overrides = $settings->validateSiteSettings($siteInput, $site)['overrides'];
		$resolved = $settings->resolve($application, $overrides);

		self::assertSame(true, $resolved['effective']['analytics']['connectors']['vendor']['metrics']['enabled']);
		self::assertSame('G-1234', $resolved['effective']['analytics']['connectors']['vendor']['metrics']['settings']['measurementId']);
		self::assertSame('eu', $resolved['effective']['analytics']['connectors']['vendor']['metrics']['settings']['region']);
		self::assertSame('site', $resolved['sources']['analytics']['connectors']['vendor']['metrics']['settings']['region']);
		self::assertCount(1, $settings->availableConnectors($site));
		self::assertSame('vendor.metrics', $settings->availableConnectors($site)[0]['key']);

		try {
			$settings->validateSiteSettings($this->object([
				'version' => 1,
				'overrides' => ['analytics' => ['connectors' => [
					'inactive' => ['metrics' => ['enabled' => false]],
				]]],
			]), $site);
			self::fail('Expected an inactive plugin connector to be rejected.');
		} catch (\InvalidArgumentException) {
		}
	}

	public function testRejectsUnknownConnectorSettingsAndRequiresApplicationEnabled(): void
	{
		$settings = new ContentOptimizationSettings(new AnalyticsConnectorRegistry([new SettingsAnalyticsConnector('vendor.metrics')]));
		$defaults = $settings->defaults();
		foreach ([
			['vendor' => ['metrics' => ['settings' => ['measurementId' => 'G-1234']]]],
			['vendor' => ['metrics' => ['enabled' => true, 'settings' => ['unknownField' => 'value']]]],
			['unknown' => ['metrics' => ['enabled' => true, 'settings' => ['measurementId' => 'G-1234']]]],
		] as $connectors) {
			$input = $defaults;
			$input['analytics']['connectors'] = $connectors;
			try {
				$settings->validateApplicationSettings($this->object($input));
				self::fail('Expected invalid connector configuration to be rejected.');
			} catch (\InvalidArgumentException) {
			}
		}
	}

	public function testWithoutRegistryOnlyAcceptsAnEmptyConnectorMap(): void
	{
		$defaults = $this->settings->defaults();
		self::assertSame([], $this->settings->validateApplicationSettings($this->object($defaults))['analytics']['connectors']);
		$siteInput = $this->object(['version' => 1, 'overrides' => ['analytics' => ['connectors' => []]]]);
		self::assertSame([], $this->settings->validateSiteSettings($siteInput)['overrides']);
		$defaults['analytics']['connectors'] = ['vendor' => ['metrics' => ['enabled' => true, 'settings' => []]]];

		try {
			$this->settings->validateApplicationSettings($this->object($defaults));
			self::fail('Expected a custom connector to be rejected without a registry.');
		} catch (\InvalidArgumentException) {
		}
		$siteInput = $this->object([
			'version' => 1,
			'overrides' => ['analytics' => ['connectors' => ['vendor' => ['metrics' => ['enabled' => true]]]]],
		]);
		$this->expectException(\InvalidArgumentException::class);
		$this->settings->validateSiteSettings($siteInput);
	}

	public function testValidatesApplicationConfigurationAndPersistsByOwner(): void
	{
		$application = new Application();
		$untouchedApplication = new Application();
		$settings = $this->settings->validateApplicationSettings($this->object($this->settings->defaults()));

		$this->settings->saveApplicationDefaults($application, $settings);
		self::assertSame($settings, $this->settings->applicationDefaults($application));
		self::assertSame($this->settings->defaults(), $this->settings->applicationDefaults($untouchedApplication));
	}

	public function testUnknownStoredConnectorDoesNotResetApplicationOrSiteCrawlPolicies(): void
	{
		$settings = new ContentOptimizationSettings(new AnalyticsConnectorRegistry([]));
		$application = new Application();
		$applicationDefaults = $settings->defaults();
		$applicationDefaults['seo']['defaultRobots']['index'] = 'noindex';
		$applicationDefaults['sitemap']['enabled'] = false;
		$applicationDefaults['crawlers']['aiAccess'] = 'disallow';
		$applicationDefaults['analytics']['connectors'] = [
			'uninstalled-plugin' => ['old-connector' => ['enabled' => true, 'settings' => ['trackingId' => 'value']]],
		];
		$applicationConfiguration = $application->getConfiguration();
		$applicationConfiguration->setPluginConfiguration(ContentOptimizationSettings::PLUGIN_SLUG, $applicationDefaults);
		$application->setConfiguration($applicationConfiguration);
		$resolvedApplication = $settings->applicationDefaults($application);

		self::assertSame('noindex', $resolvedApplication['seo']['defaultRobots']['index']);
		self::assertFalse($resolvedApplication['sitemap']['enabled']);
		self::assertSame('disallow', $resolvedApplication['crawlers']['aiAccess']);
		self::assertSame([], $resolvedApplication['analytics']['connectors']);

		$site = new Site();
		$siteConfiguration = $site->getConfiguration();
		$siteConfiguration->setPluginConfiguration(ContentOptimizationSettings::PLUGIN_SLUG, [
			'version' => 1,
			'overrides' => [
				'seo' => ['defaultRobots' => ['index' => 'noindex']],
				'sitemap' => ['enabled' => false],
				'crawlers' => ['aiAccess' => 'disallow'],
				'analytics' => ['connectors' => [
					'uninstalled-plugin' => ['old-connector' => ['enabled' => true, 'settings' => ['trackingId' => 'value']]],
				]],
			],
		]);
		$site->setConfiguration($siteConfiguration);
		$resolvedSite = $settings->siteOverrides($site);

		self::assertSame('noindex', $resolvedSite['seo']['defaultRobots']['index']);
		self::assertFalse($resolvedSite['sitemap']['enabled']);
		self::assertSame('disallow', $resolvedSite['crawlers']['aiAccess']);
		self::assertSame([], $resolvedSite['analytics']['connectors'] ?? []);
	}

	public function testValidatesSiteOverridesAndStoresOnlyThePartialOverride(): void
	{
		$site = new Site();
		$overrides = $this->settings->validateSiteSettings($this->object([
			'version' => 1,
			'overrides' => ['seo' => ['defaultSocialImage' => '/media/social.png']],
		]));

		$this->settings->saveSiteOverrides($site, $overrides['overrides']);

		self::assertSame($overrides['overrides'], $this->settings->siteOverrides($site));
		self::assertSame('/media/social.png', $this->settings->siteOverrides($site)['seo']['defaultSocialImage']);
	}

	public function testRejectsUnknownKeysInvalidShapesUnsafeUrlsAndInvalidIdentifiers(): void
	{
		$validDefaults = $this->settings->defaults();
		$invalidInputs = [
			$this->object($validDefaults + ['scriptUrl' => 'https://attacker.test/x.js']),
			$this->object(array_replace($validDefaults, ['sitemap' => []])),
			$this->object(array_replace($validDefaults, ['seo' => array_replace($validDefaults['seo'], ['defaultSocialImage' => '//attacker.test/image.png'])])),
			$this->object(array_replace($validDefaults, ['seo' => array_replace($validDefaults['seo'], ['defaultSocialImage' => 'https://example.test:65536/image.png'])])),
			$this->object(array_replace($validDefaults, ['analytics' => ['ga4' => ['enabled' => false, 'measurementId' => 'G-BAD'], 'clarity' => ['enabled' => false, 'projectId' => '']]])),
		];

		foreach ($invalidInputs as $input) {
			try {
				$this->settings->validateApplicationSettings($input);
				self::fail('Expected settings validation to fail.');
			} catch (\InvalidArgumentException) {
			}
		}
	}

	public function testRejectsNonObjectSiteOverridesAndWrongVersion(): void
	{
		foreach ([
			$this->object(['version' => 1, 'overrides' => []]),
			$this->object(['version' => 2, 'overrides' => new \stdClass()]),
		] as $input) {
			try {
				$this->settings->validateSiteSettings($input);
				self::fail('Expected settings validation to fail.');
			} catch (\InvalidArgumentException) {
			}
		}
	}

	public function testPersistedRegisteredConnectorsAndPartialSiteOverridesRoundTripCleanly(): void
	{
		$settings = new ContentOptimizationSettings(new AnalyticsConnectorRegistry([
			new SettingsAnalyticsConnector('vendor.metrics'),
		]));
		$application = new Application();
		$defaults = $settings->defaults();
		$defaults['analytics']['connectors'] = [
			'vendor' => ['metrics' => ['enabled' => true, 'settings' => ['measurementId' => 'G-1234']]],
		];
		$validatedApp = $settings->validateApplicationSettings($this->object($defaults));
		$settings->saveApplicationDefaults($application, $validatedApp);

		$reloadedApp = $settings->applicationDefaults($application);
		self::assertSame($validatedApp['analytics']['connectors'], $reloadedApp['analytics']['connectors']);

		$site = new Site();
		$site->getConfiguration()->enablePlugin('vendor');
		// Settings-only partial site override (enabled omitted)
		$settingsOnly = $settings->validateSiteSettings($this->object([
			'version' => 1,
			'overrides' => ['analytics' => ['connectors' => [
				'vendor' => ['metrics' => ['settings' => ['region' => 'eu']]],
			]]],
		]), $site)['overrides'];
		$settings->saveSiteOverrides($site, $settingsOnly);
		self::assertSame(['region' => 'eu'], $settings->siteOverrides($site)['analytics']['connectors']['vendor']['metrics']['settings']);

		// Enabled-only partial site override (settings omitted)
		$enabledOnly = $settings->validateSiteSettings($this->object([
			'version' => 1,
			'overrides' => ['analytics' => ['connectors' => [
				'vendor' => ['metrics' => ['enabled' => false]],
			]]],
		]), $site)['overrides'];
		$settings->saveSiteOverrides($site, $enabledOnly);
		self::assertFalse($settings->siteOverrides($site)['analytics']['connectors']['vendor']['metrics']['enabled']);

		// Corrupt non-object connectors map degrades cleanly without resetting unrelated SEO/crawler settings
		$siteConfig = $site->getConfiguration();
		$siteConfig->setPluginConfiguration(ContentOptimizationSettings::PLUGIN_SLUG, [
			'version' => 1,
			'overrides' => [
				'crawlers' => ['aiAccess' => 'disallow'],
				'analytics' => ['connectors' => 'corrupt-string'],
			],
		]);
		$site->setConfiguration($siteConfig);
		$reloadedSite = $settings->siteOverrides($site);
		self::assertSame('disallow', $reloadedSite['crawlers']['aiAccess']);
		self::assertArrayNotHasKey('connectors', $reloadedSite['analytics'] ?? []);
	}

	/** @param array<string,mixed> $value */
	private function object(array $value): \stdClass
	{
		$decoded = json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
		self::assertInstanceOf(\stdClass::class, $decoded);

		return $decoded;
	}
}

final class SettingsAnalyticsConnector implements AnalyticsConnectorInterface
{
	public function __construct(private readonly string $connectorKey)
	{
	}

	public function key(): string
	{
		return $this->connectorKey;
	}

	public function label(): string
	{
		return 'Metrics';
	}

	public function dashboardUrl(): ?string
	{
		return null;
	}

	public function consentCategory(): string
	{
		return 'analytics';
	}

	public function settingSchema(): array
	{
		return [
			'measurementId' => ['type' => 'string', 'label' => 'Measurement ID', 'pattern' => '/^G-[A-Z0-9]+$/'],
			'region' => ['type' => 'enum', 'label' => 'Region', 'required' => false, 'options' => ['eu' => 'Europe', 'us' => 'United States']],
		];
	}

	public function validateConfig(array $config, bool $partial = false): void
	{
	}

	public function adapterIdentifier(): string
	{
		return 'vendor-metrics';
	}

	public function scriptUrl(): string
	{
		return 'https://cdn.example.test/analytics.js';
	}

	public function buildClientDescriptor(array $validatedConfig): array
	{
		return ['adapter' => $this->adapterIdentifier(), 'scriptUrl' => $this->scriptUrl(), 'parameters' => $validatedConfig];
	}

	public function cspRequirements(): array
	{
		return ['scriptSrc' => ['https://cdn.example.test/analytics.js'], 'connectSrc' => ['https://collect.example.test'], 'imgSrc' => []];
	}
}
