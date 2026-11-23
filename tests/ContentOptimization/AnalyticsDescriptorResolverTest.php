<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\ContentOptimization;

use iikiti\CMS\Entity\Object\Site;
use iikiti\Extension\ContentOptimization\Analytics\AnalyticsConnectorInterface;
use iikiti\Extension\ContentOptimization\Analytics\AnalyticsConnectorRegistry;
use iikiti\Extension\ContentOptimization\Analytics\AnalyticsDescriptorResolver;
use iikiti\Extension\ContentOptimization\Analytics\ClarityAnalyticsConnector;
use iikiti\Extension\ContentOptimization\Analytics\GoogleAnalytics4Connector;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Analytics/AnalyticsConnectorInterface.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Analytics/AnalyticsConnectorRegistry.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Analytics/BuiltInAnalyticsConnectorInterface.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Analytics/GoogleAnalytics4Connector.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Analytics/ClarityAnalyticsConnector.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Analytics/AnalyticsDescriptorResolver.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/ContentOptimizationSettings.php';

final class AnalyticsDescriptorResolverTest extends TestCase
{
	public function testReturnsBuiltInDescriptorsWithExactConsentAndCspMetadata(): void
	{
		$resolver = $this->resolver(new GoogleAnalytics4Connector(), new ClarityAnalyticsConnector());
		$site = $this->activeSite();

		self::assertSame([
			'requireConsent' => true,
			'category' => 'analytics',
			'connectors' => [
				[
					'key' => 'iikiti-content-optimization.ga4',
					'adapter' => 'ga4',
					'scriptUrl' => 'https://www.googletagmanager.com/gtag/js',
					'parameters' => ['measurementId' => 'G-ABCD1234'],
					'cspRequirements' => [
						'scriptSrc' => ['https://www.googletagmanager.com/gtag/js'],
						'connectSrc' => ['https://www.google-analytics.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com'],
						'imgSrc' => ['https://*.google-analytics.com'],
					],
				],
				[
					'key' => 'iikiti-content-optimization.clarity',
					'adapter' => 'clarity',
					'scriptUrl' => 'https://www.clarity.ms/tag/',
					'parameters' => ['projectId' => 'abc123def'],
					'cspRequirements' => [
						'scriptSrc' => ['https://www.clarity.ms/tag/', 'https://scripts.clarity.ms/'],
						'connectSrc' => ['https://*.clarity.ms'],
						'imgSrc' => ['https://c.clarity.ms', 'https://c.bing.com'],
					],
				],
			],
		], $resolver->resolve($site, [
			'analytics' => [
				'ga4' => ['enabled' => true, 'measurementId' => 'G-ABCD1234'],
				'clarity' => ['enabled' => true, 'projectId' => 'abc123def'],
			],
		], true, false));
	}

	public function testSkipsBuiltInsWithMalformedIdentifiers(): void
	{
		$resolver = $this->resolver(new GoogleAnalytics4Connector(), new ClarityAnalyticsConnector());

		$config = $resolver->resolve($this->activeSite(), [
			'analytics' => [
				'ga4' => ['enabled' => true, 'measurementId' => 'G-abc123'],
				'clarity' => ['enabled' => true, 'projectId' => 'INVALID'],
			],
		], true, false);

		self::assertSame([], $config['connectors']);
	}

	public function testReturnsNoConnectorsWhenSitePluginConsentPluginOrEditorGateIsInactive(): void
	{
		$resolver = $this->resolver(new GoogleAnalytics4Connector());
		$settings = ['analytics' => ['ga4' => ['enabled' => true, 'measurementId' => 'G-ABCD1234']]];

		self::assertSame([], $resolver->resolve($this->activeSite(false), $settings, true, false)['connectors']);
		self::assertSame([], $resolver->resolve($this->activeSite(true, false), $settings, true, false)['connectors']);
		self::assertSame([], $resolver->resolve($this->activeSite(), $settings, false, false)['connectors']);
		self::assertSame([], $resolver->resolve($this->activeSite(), $settings, true, true)['connectors']);
	}

	public function testIncludesEnabledCustomProvidersOnlyForActiveOwnerPlugins(): void
	{
		$custom = new AnalyticsDescriptorTestConnector();
		$resolver = $this->resolver(new GoogleAnalytics4Connector(), $custom);
		$site = $this->activeSite();
		$site->getConfiguration()->enablePlugin('vendor-metrics');

		$config = $resolver->resolve($site, [
			'analytics' => [
				'connectors' => [
					'vendor-metrics' => [
						'events' => ['enabled' => true, 'settings' => ['accountId' => 'acct123']],
					],
				],
			],
		], true, false);

		self::assertSame([[
			'key' => 'vendor-metrics.events',
			'adapter' => 'vendor-events',
			'scriptUrl' => 'https://cdn.vendor.test/events.js',
			'parameters' => ['accountId' => 'acct123'],
			'cspRequirements' => [
				'scriptSrc' => ['https://cdn.vendor.test/events.js'],
				'connectSrc' => ['https://collect.vendor.test'],
				'imgSrc' => [],
			],
		]], $config['connectors']);

		$siteWithoutOwner = $this->activeSite();
		self::assertSame([], $resolver->resolve($siteWithoutOwner, [
			'analytics' => ['connectors' => ['vendor-metrics' => ['events' => ['enabled' => true, 'settings' => ['accountId' => 'acct123']]]]],
		], true, false)['connectors']);
	}

	public function testAvailableConnectorsOmitsMarkedBuiltIns(): void
	{
		$registry = new AnalyticsConnectorRegistry([
			new GoogleAnalytics4Connector(),
			new ClarityAnalyticsConnector(),
			new AnalyticsDescriptorTestConnector(),
		]);
		$settings = new ContentOptimizationSettings($registry);

		self::assertSame(['vendor-metrics.events'], array_column($settings->availableConnectors(), 'key'));
	}

	private function resolver(AnalyticsConnectorInterface ...$connectors): AnalyticsDescriptorResolver
	{
		return new AnalyticsDescriptorResolver(new AnalyticsConnectorRegistry($connectors));
	}

	private function activeSite(bool $contentOptimization = true, bool $consentManagement = true): Site
	{
		$site = new Site();
		if ($contentOptimization) {
			$site->getConfiguration()->enablePlugin(ContentOptimizationSettings::PLUGIN_SLUG);
		} else {
			$site->getConfiguration()->disablePlugin(ContentOptimizationSettings::PLUGIN_SLUG);
		}
		if ($consentManagement) {
			$site->getConfiguration()->enablePlugin('consent-management');
		}

		return $site;
	}
}

final class AnalyticsDescriptorTestConnector implements AnalyticsConnectorInterface
{
	public function key(): string
	{
		return 'vendor-metrics.events';
	}

	public function label(): string
	{
		return 'Vendor Events';
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
		return ['accountId' => ['type' => 'string', 'label' => 'Account ID', 'pattern' => '/^[a-z0-9]{6,20}$/D']];
	}

	public function validateConfig(array $config, bool $partial = false): void
	{
	}

	public function adapterIdentifier(): string
	{
		return 'vendor-events';
	}

	public function scriptUrl(): string
	{
		return 'https://cdn.vendor.test/events.js';
	}

	public function buildClientDescriptor(array $validatedConfig): array
	{
		return ['adapter' => $this->adapterIdentifier(), 'scriptUrl' => $this->scriptUrl(), 'parameters' => $validatedConfig];
	}

	public function cspRequirements(): array
	{
		return ['scriptSrc' => [$this->scriptUrl()], 'connectSrc' => ['https://collect.vendor.test'], 'imgSrc' => []];
	}
}
