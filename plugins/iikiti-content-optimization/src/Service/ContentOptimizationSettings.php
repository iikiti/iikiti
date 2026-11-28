<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Service;

use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\Extension\ContentOptimization\Analytics\AnalyticsConnectorRegistry;
use iikiti\Extension\ContentOptimization\Analytics\BuiltInAnalyticsConnectorInterface;

final class ContentOptimizationSettings
{
	public const PLUGIN_SLUG = 'iikiti-content-optimization';

	private const MAX_TITLE_LENGTH = 200;
	private const MAX_DESCRIPTION_LENGTH = 1000;
	private const MAX_IMAGE_URL_LENGTH = 2048;

	public function __construct(private readonly ?AnalyticsConnectorRegistry $analyticsConnectors = null)
	{
	}

	/**
	 * @return array<string,mixed>
	 */
	public function defaults(): array
	{
		return [
			'version' => 1,
			'seo' => [
				'titleTemplate' => '{pageTitle} | {siteName}',
				'defaultDescription' => '',
				'defaultSocialImage' => '',
				'defaultRobots' => ['index' => 'index', 'follow' => 'follow'],
			],
			'sitemap' => ['enabled' => true],
			'crawlers' => ['aiAccess' => 'allow'],
			'analytics' => [
				'ga4' => ['enabled' => false, 'measurementId' => ''],
				'clarity' => ['enabled' => false, 'projectId' => ''],
				'connectors' => [],
			],
		];
	}

	/** @return array<string,mixed> */
	public function validateApplicationSettings(\stdClass $input): array
	{
		$this->assertFields($input, ['version', 'seo', 'sitemap', 'crawlers', 'analytics']);
		$this->assertVersion($input);

		return [
			'version' => 1,
			'seo' => $this->validateSeo($this->objectField($input, 'seo'), true),
			'sitemap' => $this->validateSitemap($this->objectField($input, 'sitemap'), true),
			'crawlers' => $this->validateCrawlers($this->objectField($input, 'crawlers'), true),
			'analytics' => $this->validateAnalytics($this->objectField($input, 'analytics'), true),
		];
	}

	/** @return array{version:int,overrides:array<string,mixed>} */
	public function validateSiteSettings(\stdClass $input, ?Site $site = null): array
	{
		$this->assertFields($input, ['version', 'overrides']);
		$this->assertVersion($input);
		$overrides = $this->objectField($input, 'overrides');
		$this->assertFields($overrides, ['seo', 'sitemap', 'crawlers', 'analytics']);

		$validated = [];
		foreach (['seo', 'sitemap', 'crawlers', 'analytics'] as $section) {
			if (!property_exists($overrides, $section)) {
				continue;
			}
			$sectionObject = $this->objectField($overrides, $section);
			$sectionValues = match ($section) {
				'seo' => $this->validateSeo($sectionObject, false),
				'sitemap' => $this->validateSitemap($sectionObject, false),
				'crawlers' => $this->validateCrawlers($sectionObject, false),
				'analytics' => $this->validateAnalytics($sectionObject, false, $site),
			};
			if ([] !== $sectionValues) {
				$validated[$section] = $sectionValues;
			}
		}

		return ['version' => 1, 'overrides' => $validated];
	}

	/** @return array<string,mixed> */
	public function applicationDefaults(Application $application): array
	{
		$stored = $this->objectFromStored((array) $application->getConfiguration()->getPluginConfiguration(self::PLUGIN_SLUG));
		if (null === $stored) {
			return $this->defaults();
		}
		$stored = $this->stripInvalidStoredConnectors($stored, null, false);

		try {
			return $this->validateApplicationSettings($stored);
		} catch (\InvalidArgumentException) {
			return $this->defaults();
		}
	}

	/** @return array<string,mixed> */
	public function siteOverrides(Site $site): array
	{
		$stored = (array) $site->getConfiguration()->getPluginConfiguration(self::PLUGIN_SLUG);
		if (!array_key_exists('version', $stored) || !array_key_exists('overrides', $stored)) {
			return [];
		}
		$storedObject = $this->objectFromStored($stored);
		if (null === $storedObject) {
			return [];
		}
		if (property_exists($storedObject, 'overrides') && $storedObject->overrides instanceof \stdClass) {
			$storedObject->overrides = $this->stripInvalidStoredConnectors($storedObject->overrides, $site, true);
		}

		try {
			return $this->validateSiteSettings($storedObject, $site)['overrides'];
		} catch (\InvalidArgumentException) {
			return [];
		}
	}

	/**
	 * @param array<string,mixed> $defaults
	 * @param array<string,mixed> $overrides
	 *
	 * @return array{effective:array<string,mixed>,sources:array<string,mixed>}
	 */
	public function resolve(array $defaults, array $overrides): array
	{
		$effective = [];
		$sources = [];
		foreach (array_unique([...array_keys($defaults), ...array_keys($overrides)]) as $key) {
			$hasDefault = array_key_exists($key, $defaults);
			$hasOverride = array_key_exists($key, $overrides);
			$defaultValue = $hasDefault ? $defaults[$key] : null;
			$overrideValue = $hasOverride ? $overrides[$key] : null;
			if ('defaultSocialImage' !== $key && (is_array($defaultValue) || is_array($overrideValue))) {
				$nested = $this->resolve(is_array($defaultValue) ? $defaultValue : [], is_array($overrideValue) ? $overrideValue : []);
				$effective[$key] = $nested['effective'];
				$sources[$key] = $nested['sources'];
				continue;
			}
			$effective[$key] = $hasOverride ? $overrideValue : $defaultValue;
			$sources[$key] = $hasOverride ? 'site' : 'application';
		}

		return ['effective' => $effective, 'sources' => $sources];
	}

	/** @param array<string,mixed> $settings */
	public function saveApplicationDefaults(Application $application, array $settings): void
	{
		$configuration = $application->getConfiguration();
		$configuration->setPluginConfiguration(self::PLUGIN_SLUG, $settings);
		$application->setConfiguration($configuration);
	}

	/** @param array<string,mixed> $overrides */
	public function saveSiteOverrides(Site $site, array $overrides): void
	{
		$configuration = $site->getConfiguration();
		$configuration->setPluginConfiguration(self::PLUGIN_SLUG, ['version' => 1, 'overrides' => $overrides]);
		$site->setConfiguration($configuration);
	}

	/** @return list<array{key:string,label:string,dashboardUrl:?string,settingSchema:array<string,array<string,mixed>>,cspRequirements:array<string,list<string>>}> */
	public function availableConnectors(?Site $site = null): array
	{
		if (null === $this->analyticsConnectors) {
			return [];
		}

		$connectors = null === $site ? $this->analyticsConnectors->all() : $this->analyticsConnectors->forSite($site);
		$descriptors = [];
		foreach ($connectors as $key => $connector) {
			if ($connector instanceof BuiltInAnalyticsConnectorInterface) {
				continue;
			}
			$descriptors[] = [
				'key' => $key,
				'label' => $connector->label(),
				'dashboardUrl' => $this->analyticsConnectors->dashboardUrl($key),
				'settingSchema' => $this->analyticsConnectors->settingSchema($key),
				'cspRequirements' => $this->analyticsConnectors->cspRequirements($key),
			];
		}

		return $descriptors;
	}

	/** @return array<string,mixed> */
	private function validateSeo(\stdClass $seo, bool $required): array
	{
		$this->assertFields($seo, ['titleTemplate', 'defaultDescription', 'defaultSocialImage', 'defaultRobots']);
		$values = [];
		foreach (['titleTemplate', 'defaultDescription', 'defaultSocialImage', 'defaultRobots'] as $field) {
			if (!$required && !property_exists($seo, $field)) {
				continue;
			}
			if (!property_exists($seo, $field)) {
				throw new \InvalidArgumentException(sprintf('SEO field "%s" is required.', $field));
			}
			$value = match ($field) {
				'titleTemplate' => $this->stringField($seo, $field, self::MAX_TITLE_LENGTH),
				'defaultDescription' => $this->stringField($seo, $field, self::MAX_DESCRIPTION_LENGTH),
				'defaultSocialImage' => $this->socialImageField($seo, $field),
				'defaultRobots' => $this->validateRobots($this->objectField($seo, $field), $required),
			};
			if (!is_array($value) || [] !== $value) {
				$values[$field] = $value;
			}
		}

		return $values;
	}

	/** @return array<string,string> */
	private function validateRobots(\stdClass $robots, bool $required): array
	{
		$this->assertFields($robots, ['index', 'follow']);
		$values = [];
		foreach (['index' => ['index', 'noindex'], 'follow' => ['follow', 'nofollow']] as $field => $allowed) {
			if (!$required && !property_exists($robots, $field)) {
				continue;
			}
			$value = $this->stringField($robots, $field, 8);
			if (!in_array($value, $allowed, true)) {
				throw new \InvalidArgumentException(sprintf('SEO robots "%s" value is invalid.', $field));
			}
			$values[$field] = $value;
		}

		return $values;
	}

	/** @return array<string,bool> */
	private function validateSitemap(\stdClass $sitemap, bool $required): array
	{
		$this->assertFields($sitemap, ['enabled']);
		if (!$required && !property_exists($sitemap, 'enabled')) {
			return [];
		}

		return ['enabled' => $this->booleanField($sitemap, 'enabled')];
	}

	/** @return array<string,string> */
	private function validateCrawlers(\stdClass $crawlers, bool $required): array
	{
		$this->assertFields($crawlers, ['aiAccess']);
		if (!$required && !property_exists($crawlers, 'aiAccess')) {
			return [];
		}
		$value = $this->stringField($crawlers, 'aiAccess', 8);
		if (!in_array($value, ['allow', 'disallow'], true)) {
			throw new \InvalidArgumentException('Crawler AI access must be allow or disallow.');
		}

		return ['aiAccess' => $value];
	}

	/** @return array<string,mixed> */
	private function validateAnalytics(\stdClass $analytics, bool $required, ?Site $site = null): array
	{
		$this->assertFields($analytics, ['ga4', 'clarity', 'connectors']);
		$values = [];
		foreach (['ga4', 'clarity'] as $provider) {
			if (!$required && !property_exists($analytics, $provider)) {
				continue;
			}
			$providerObject = $this->objectField($analytics, $provider);
			if ('ga4' === $provider) {
				$this->assertFields($providerObject, ['enabled', 'measurementId']);
				$providerValues = [];
				if ($required || property_exists($providerObject, 'enabled')) {
					$providerValues['enabled'] = $this->requiredBoolean($providerObject, 'enabled');
				}
				if ($required || property_exists($providerObject, 'measurementId')) {
					$providerValues['measurementId'] = $this->identifierField($providerObject, 'measurementId', '/^G-[A-Z0-9]{4,20}$/D', 22);
				}
				if ([] !== $providerValues) {
					$values[$provider] = $providerValues;
				}
				continue;
			}
			$this->assertFields($providerObject, ['enabled', 'projectId']);
			$providerValues = [];
			if ($required || property_exists($providerObject, 'enabled')) {
				$providerValues['enabled'] = $this->requiredBoolean($providerObject, 'enabled');
			}
			if ($required || property_exists($providerObject, 'projectId')) {
				$providerValues['projectId'] = $this->identifierField($providerObject, 'projectId', '/^[a-z0-9]{6,20}$/D', 20);
			}
			if ([] !== $providerValues) {
				$values[$provider] = $providerValues;
			}
		}
		if ($required || property_exists($analytics, 'connectors')) {
			$connectorMap = property_exists($analytics, 'connectors') ? $this->connectorMapField($analytics) : new \stdClass();
			$connectors = $this->validateConnectors($connectorMap, $required, $site);
			if ([] !== $connectors || $required) {
				$values['connectors'] = $connectors;
			}
		}

		return $values;
	}

	/** @return array<string,array<string,array{enabled?:bool,settings?:array<string,scalar>}>> */
	private function validateConnectors(\stdClass $input, bool $application, ?Site $site): array
	{
		$registered = null === $this->analyticsConnectors ? [] : (null === $site ? $this->analyticsConnectors->all() : $this->analyticsConnectors->forSite($site));
		$validated = [];
		foreach (get_object_vars($input) as $pluginSlug => $connectorMap) {
			if (!is_string($pluginSlug) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $pluginSlug) || !$connectorMap instanceof \stdClass) {
				throw new \InvalidArgumentException('Analytics connectors must be mapped by plugin slug and connector ID.');
			}
			foreach (get_object_vars($connectorMap) as $connectorId => $config) {
				$key = $pluginSlug.'.'.$connectorId;
				if (!is_string($connectorId) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $connectorId) || !array_key_exists($key, $registered)) {
					throw new \InvalidArgumentException(sprintf('Analytics connector "%s" is unknown or inactive for this owner.', $key));
				}
				if (!$config instanceof \stdClass) {
					throw new \InvalidArgumentException(sprintf('Analytics connector "%s" config must be an object.', $key));
				}
				$this->assertFields($config, ['enabled', 'settings']);
				$entry = [];
				$enabled = false;
				if ($application || property_exists($config, 'enabled')) {
					$entry['enabled'] = $this->requiredBoolean($config, 'enabled');
					$enabled = $entry['enabled'];
				}
				if ($application || property_exists($config, 'settings')) {
					if (!property_exists($config, 'settings') && $application && $enabled) {
						throw new \InvalidArgumentException(sprintf('Analytics connector "%s" settings must be an object.', $key));
					}
					$settingsObject = property_exists($config, 'settings') ? $this->objectField($config, 'settings') : new \stdClass();
					$entry['settings'] = $this->analyticsConnectors->validateConfig($key, get_object_vars($settingsObject), !$application || !$enabled);
				}
				$validated[$pluginSlug][$connectorId] = $entry;
			}
		}

		return $validated;
	}

	private function stripInvalidStoredConnectors(\stdClass $settings, ?Site $site = null, bool $isSiteOverride = false): \stdClass
	{
		if (!property_exists($settings, 'analytics') || !$settings->analytics instanceof \stdClass) {
			return $settings;
		}

		$analytics = $settings->analytics;
		if (!property_exists($analytics, 'connectors')) {
			return $settings;
		}
		if (!$analytics->connectors instanceof \stdClass) {
			if ($isSiteOverride) {
				unset($analytics->connectors);
			} else {
				$analytics->connectors = new \stdClass();
			}

			return $settings;
		}

		$available = null === $this->analyticsConnectors ?
			[] :
			(null === $site ? $this->analyticsConnectors->all() : $this->analyticsConnectors->forSite($site));
		$connectorMap = $analytics->connectors;
		$validatedMap = new \stdClass();

		foreach (get_object_vars($connectorMap) as $pluginSlug => $connectors) {
			if (!is_string($pluginSlug) || !$connectors instanceof \stdClass) {
				continue;
			}
			$validConnectors = new \stdClass();
			foreach (get_object_vars($connectors) as $connectorId => $configuration) {
				$key = $pluginSlug.'.'.$connectorId;
				if (!is_string($connectorId) || !isset($available[$key]) || !$configuration instanceof \stdClass) {
					continue;
				}

				try {
					$this->assertFields($configuration, ['enabled', 'settings']);
					$entry = new \stdClass();
					$enabled = false;
					if (!$isSiteOverride) {
						$enabled = $this->requiredBoolean($configuration, 'enabled');
						$entry->enabled = $enabled;
					} elseif (property_exists($configuration, 'enabled')) {
						$enabled = $this->booleanField($configuration, 'enabled');
						$entry->enabled = $enabled;
					}

					if (!$isSiteOverride || property_exists($configuration, 'settings')) {
						if (!property_exists($configuration, 'settings') && !$isSiteOverride && $enabled) {
							throw new \InvalidArgumentException('Connector settings are required when enabled.');
						}
						$rawSettings = property_exists($configuration, 'settings') ? $this->objectField($configuration, 'settings') : new \stdClass();
						$validatedSettings = $this->analyticsConnectors->validateConfig($key, get_object_vars($rawSettings), $isSiteOverride || !$enabled);
						$entry->settings = (object) $validatedSettings;
					}

					if ([] !== get_object_vars($entry)) {
						$validConnectors->{$connectorId} = $entry;
					}
				} catch (\InvalidArgumentException) {
					// Ignore a stale/bad connector entry without discarding SEO or crawler settings.
				}
			}
			if ([] !== get_object_vars($validConnectors)) {
				$validatedMap->{$pluginSlug} = $validConnectors;
			}
		}

		if ($isSiteOverride && [] === get_object_vars($validatedMap)) {
			unset($analytics->connectors);
		} else {
			$analytics->connectors = $validatedMap;
		}

		return $settings;
	}

	private function requiredBoolean(\stdClass $object, string $field): bool
	{
		if (!property_exists($object, $field)) {
			throw new \InvalidArgumentException(sprintf('Field "%s" is required.', $field));
		}

		return $this->booleanField($object, $field);
	}

	private function booleanField(\stdClass $object, string $field): bool
	{
		$value = $object->{$field} ?? null;
		if (!is_bool($value)) {
			throw new \InvalidArgumentException(sprintf('Field "%s" must be a boolean.', $field));
		}

		return $value;
	}

	private function identifierField(\stdClass $object, string $field, string $pattern, int $maximumLength): string
	{
		$value = $this->stringField($object, $field, $maximumLength);
		if ('' !== $value && 1 !== preg_match($pattern, $value)) {
			throw new \InvalidArgumentException(sprintf('Field "%s" has an invalid identifier.', $field));
		}

		return $value;
	}

	/** @return string|array<string,mixed> */
	private function socialImageField(\stdClass $object, string $field): string|array
	{
		$raw = $object->{$field} ?? null;
		if (is_string($raw)) {
			return $this->urlField($object, $field);
		}
		if (!$raw instanceof \stdClass) {
			throw new \InvalidArgumentException(sprintf('Field "%s" must be a URL string or media object.', $field));
		}

		$this->assertFields($raw, [
			'source',
			'assetId',
			'publicToken',
			'url',
			'previewUrl',
			'thumbnailUrl',
			'mimeType',
			'width',
			'height',
			'focalPoint',
			'dominantColor',
			'defaultAlt',
		]);
		$source = strtolower($this->stringField($raw, 'source', 16));

		if ('empty' === $source) {
			return '';
		}

		if ('asset' === $source) {
			$rawAssetId = property_exists($raw, 'assetId') ? $raw->assetId : null;
			if (null === $rawAssetId || '' === $rawAssetId) {
				return '';
			}
			$assetId = null;
			if (is_int($rawAssetId) && $rawAssetId > 0) {
				$assetId = $rawAssetId;
			} elseif (is_string($rawAssetId) && ctype_digit(trim($rawAssetId)) && (int) trim($rawAssetId) > 0) {
				$assetId = (int) trim($rawAssetId);
			}
			if (null === $assetId) {
				throw new \InvalidArgumentException(sprintf('Field "%s" assetId must be a positive integer.', $field));
			}

			$result = [
				'source' => 'asset',
				'assetId' => $assetId,
			];

			if (property_exists($raw, 'publicToken') && null !== $raw->publicToken && '' !== $raw->publicToken) {
				$token = strtolower($this->stringField($raw, 'publicToken', 64));
				if (1 !== preg_match('/^[a-f0-9]{64}$/', $token)) {
					throw new \InvalidArgumentException(sprintf('Field "%s" publicToken is invalid.', $field));
				}
				$result['publicToken'] = $token;
			}

			foreach (['url', 'previewUrl', 'thumbnailUrl'] as $urlKey) {
				if (property_exists($raw, $urlKey) && null !== $raw->{$urlKey} && '' !== $raw->{$urlKey}) {
					$url = $this->urlField($raw, $urlKey);
					if ('' !== $url) {
						$result[$urlKey] = $url;
					}
				}
			}

			if (property_exists($raw, 'focalPoint') && null !== $raw->focalPoint) {
				$focal = $raw->focalPoint;
				if (
					!$focal instanceof \stdClass
					|| !property_exists($focal, 'x')
					|| !property_exists($focal, 'y')
					|| (!is_int($focal->x) && !is_float($focal->x))
					|| (!is_int($focal->y) && !is_float($focal->y))
				) {
					throw new \InvalidArgumentException(sprintf('Field "%s" focalPoint must provide numeric x and y coordinates.', $field));
				}
				$x = (float) $focal->x;
				$y = (float) $focal->y;
				if (!is_finite($x) || !is_finite($y) || $x < 0.0 || $x > 1.0 || $y < 0.0 || $y > 1.0) {
					throw new \InvalidArgumentException(sprintf('Field "%s" focalPoint coordinates must be between 0.0 and 1.0.', $field));
				}
				$result['focalPoint'] = ['x' => $x, 'y' => $y];
			}

			return $result;
		}

		if ('url' === $source) {
			if (!property_exists($raw, 'url') || null === $raw->url) {
				return '';
			}
			$url = $this->urlField($raw, 'url');
			if ('' === $url) {
				return '';
			}

			return [
				'source' => 'url',
				'url' => $url,
			];
		}

		throw new \InvalidArgumentException(sprintf('Field "%s" media source must be "asset" or "url".', $field));
	}

	private function urlField(\stdClass $object, string $field): string
	{
		$value = $this->stringField($object, $field, self::MAX_IMAGE_URL_LENGTH);
		if ('' === $value) {
			return '';
		}
		if (preg_match('/\s/u', $value) || str_contains($value, '\\') || 1 === preg_match('/%(?![A-F0-9]{2})/i', $value)) {
			throw new \InvalidArgumentException(sprintf('Field "%s" must be a safe URL.', $field));
		}
		try {
			$parts = parse_url($value);
		} catch (\ValueError) {
			throw new \InvalidArgumentException(sprintf('Field "%s" must be a safe URL.', $field));
		}
		if (false === $parts || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
			throw new \InvalidArgumentException(sprintf('Field "%s" must be a safe URL.', $field));
		}
		if (str_starts_with($value, '/')) {
			if (str_starts_with($value, '//') || isset($parts['scheme']) || isset($parts['host'])) {
				throw new \InvalidArgumentException(sprintf('Field "%s" must be HTTPS or root-relative.', $field));
			}

			return $value;
		}
		if ('https' !== strtolower($parts['scheme'] ?? '') || empty($parts['host']) || false === filter_var($value, FILTER_VALIDATE_URL)) {
			throw new \InvalidArgumentException(sprintf('Field "%s" must be HTTPS or root-relative.', $field));
		}

		return $value;
	}

	private function stringField(\stdClass $object, string $field, int $maximumLength): string
	{
		$value = $object->{$field} ?? null;
		if (!is_string($value) || 1 !== preg_match('//u', $value) || 1 === preg_match('/\p{Cc}/u', $value)) {
			throw new \InvalidArgumentException(sprintf('Field "%s" must be valid text.', $field));
		}
		$value = trim($value);
		$length = preg_match_all('/./us', $value);
		if (false === $length || $length > $maximumLength) {
			throw new \InvalidArgumentException(sprintf('Field "%s" exceeds its maximum length.', $field));
		}

		return $value;
	}

	private function objectField(\stdClass $object, string $field): \stdClass
	{
		$value = $object->{$field} ?? null;
		if (!$value instanceof \stdClass) {
			throw new \InvalidArgumentException(sprintf('Field "%s" must be an object.', $field));
		}

		return $value;
	}

	private function connectorMapField(\stdClass $analytics): \stdClass
	{
		$value = $analytics->connectors;
		if ($value instanceof \stdClass) {
			return $value;
		}
		if (is_array($value) && [] === $value) {
			return new \stdClass();
		}

		throw new \InvalidArgumentException('Field "connectors" must be an object map.');
	}

	/** @param list<string> $allowed */
	private function assertFields(\stdClass $object, array $allowed): void
	{
		foreach (array_keys(get_object_vars($object)) as $field) {
			if (!in_array($field, $allowed, true)) {
				throw new \InvalidArgumentException(sprintf('Unknown settings field "%s".', $field));
			}
		}
	}

	private function assertVersion(\stdClass $object): void
	{
		if (!property_exists($object, 'version') || 1 !== $object->version) {
			throw new \InvalidArgumentException('Settings version must be 1.');
		}
	}

	/** @param array<array-key,mixed> $values */
	private function objectFromStored(array $values): ?\stdClass
	{
		$object = new \stdClass();
		foreach ($values as $key => $value) {
			if (!is_string($key)) {
				return null;
			}
			$object->{$key} = $this->storedValueToObject($value);
		}

		return $object;
	}

	private function storedValueToObject(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}
		$object = new \stdClass();
		foreach ($value as $key => $nested) {
			if (!is_string($key)) {
				return $value;
			}
			$object->{$key} = $this->storedValueToObject($nested);
		}

		return $object;
	}
}
