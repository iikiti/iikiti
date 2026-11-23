<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Analytics;

use iikiti\CMS\Entity\Object\Site;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;

/** Resolves consent-gated, inert analytics client configuration for a site. */
final class AnalyticsDescriptorResolver
{
	private const CONSENT_PLUGIN_SLUG = 'consent-management';

	public function __construct(private readonly AnalyticsConnectorRegistry $connectors)
	{
	}

	/**
	 * Return an inert configuration. No provider is included unless the owning
	 * plugin, consent-management, and the non-editor request gates all pass.
	 * Each connector includes its validated descriptor and exact CSP requirements.
	 *
	 * @param array<string,mixed> $effectiveSettings
	 *
	 * @return array{requireConsent:true,category:'analytics',connectors:list<array{key:string,adapter:string,scriptUrl:string,parameters:array<string,scalar>,cspRequirements:array{scriptSrc:list<string>,connectSrc:list<string>,imgSrc:list<string>}}>}
	 */
	public function resolve(Site $site, array $effectiveSettings, bool $consentProviderActive, bool $editorMode): array
	{
		$result = ['requireConsent' => true, 'category' => 'analytics', 'connectors' => []];
		$enabledPlugins = $site->getEnabledPlugins();
		if (!in_array(ContentOptimizationSettings::PLUGIN_SLUG, $enabledPlugins, true) ||
			!in_array(self::CONSENT_PLUGIN_SLUG, $enabledPlugins, true) ||
			!$consentProviderActive ||
			$editorMode
		) {
			return $result;
		}

		$analytics = $effectiveSettings['analytics'] ?? null;
		if (!is_array($analytics)) {
			return $result;
		}

		foreach ($this->connectors->forSite($site) as $key => $connector) {
			if ($connector instanceof BuiltInAnalyticsConnectorInterface) {
				$provider = match ($key) {
					GoogleAnalytics4Connector::KEY => 'ga4',
					ClarityAnalyticsConnector::KEY => 'clarity',
					default => null,
				};
				if (null === $provider) {
					continue;
				}
				$providerSettings = $analytics[$provider] ?? null;
				$enabled = is_array($providerSettings) && true === ($providerSettings['enabled'] ?? false);
				$settings = match ($provider) {
					'ga4' => ['measurementId' => $providerSettings['measurementId'] ?? null],
					'clarity' => ['projectId' => $providerSettings['projectId'] ?? null],
				};
			} else {
				[$pluginSlug, $connectorId] = explode('.', $key, 2);
				$connectorMap = $analytics['connectors'] ?? null;
				$ownerSettings = is_array($connectorMap) ? ($connectorMap[$pluginSlug] ?? null) : null;
				$providerSettings = is_array($ownerSettings) ? ($ownerSettings[$connectorId] ?? null) : null;
				$enabled = is_array($providerSettings) && true === ($providerSettings['enabled'] ?? false);
				$settings = is_array($providerSettings) ? ($providerSettings['settings'] ?? null) : null;
			}

			if (!$enabled || !is_array($settings)) {
				continue;
			}

			try {
				$descriptor = $this->connectors->clientDescriptor($key, $settings);
				$result['connectors'][] = [
					'key' => $key,
					'adapter' => $descriptor['adapter'],
					'scriptUrl' => $descriptor['scriptUrl'],
					'parameters' => $descriptor['parameters'],
					'cspRequirements' => $this->connectors->cspRequirements($key),
				];
			} catch (\Throwable) {
				// A malformed provider configuration or descriptor must never be emitted.
			}
		}

		return $result;
	}
}
