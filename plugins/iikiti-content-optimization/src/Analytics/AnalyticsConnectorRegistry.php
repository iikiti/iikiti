<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Analytics;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Indexes and validates plugin-provided analytics connector contracts. */
final class AnalyticsConnectorRegistry
{
	private const MAX_STRING_SETTING_LENGTH = 4096;
	private const BUILT_IN_NAMESPACE_PREFIX = 'iikiti\\Extension\\ContentOptimization\\';

	/** @var array<string,AnalyticsConnectorInterface> */
	private array $connectors = [];

	/** @var array<string,array{scriptSrc:list<string>,connectSrc:list<string>,imgSrc:list<string>}> */
	private array $cspRequirements = [];

	/** @var array<string,array<string,array<string,mixed>>> */
	private array $schemas = [];

	/** @var array<string,string> */
	private array $adapters = [];

	/** @var array<string,string> */
	private array $scriptUrls = [];

	/** @var array<string,string|null> */
	private array $dashboardUrls = [];

	/** @param iterable<mixed> $connectors */
	public function __construct(
		#[AutowireIterator('iikiti.analytics_connector')] iterable $connectors,
		?PluginRegistry $plugins = null,
		?LoggerInterface $logger = null,
	) {
		foreach ($connectors as $connector) {
			$isBuiltIn = $connector instanceof BuiltInAnalyticsConnectorInterface
				&& str_starts_with($connector::class, self::BUILT_IN_NAMESPACE_PREFIX);
			try {
				$this->registerConnector($connector, $plugins);
			} catch (\InvalidArgumentException $exception) {
				if ($isBuiltIn || null === $plugins) {
					throw $exception;
				}
				// Isolate malformed or colliding third-party connectors so a single broken
				// plugin cannot take down PageRendering on unrelated sites.
				$logger?->warning('Skipped invalid third-party analytics connector.', [
					'connectorClass' => is_object($connector) ? $connector::class : get_debug_type($connector),
					'reason' => $exception->getMessage(),
				]);
			}
		}
	}

	private function registerConnector(mixed $connector, ?PluginRegistry $plugins): void
	{
		if (!$connector instanceof AnalyticsConnectorInterface) {
			throw new \InvalidArgumentException('Every analytics connector service must implement AnalyticsConnectorInterface.');
		}

		if ($connector instanceof BuiltInAnalyticsConnectorInterface && !str_starts_with($connector::class, self::BUILT_IN_NAMESPACE_PREFIX)) {
			throw new \InvalidArgumentException(sprintf('Built-in analytics connector interface is reserved for "%s".', self::BUILT_IN_NAMESPACE_PREFIX));
		}

		$key = $connector->key();
		if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\.[a-z0-9]+(?:-[a-z0-9]+)*$/D', $key)) {
			throw new \InvalidArgumentException(sprintf('Analytics connector key "%s" must use plugin-slug.connector-id form.', $key));
		}
		[$pluginSlug] = explode('.', $key, 2);
		if (ContentOptimizationSettings::PLUGIN_SLUG === $pluginSlug && !str_starts_with($connector::class, self::BUILT_IN_NAMESPACE_PREFIX)) {
			throw new \InvalidArgumentException(sprintf('Analytics connector key prefix "%s." is reserved for the Content Optimization plugin.', $pluginSlug));
		}
		if (null !== $plugins) {
			$manifest = $plugins->getManifests()[$pluginSlug] ?? null;
			if (null === $manifest || !str_starts_with($connector::class, $manifest->namespace.'\\')) {
				throw new \InvalidArgumentException(sprintf('Analytics connector "%s" does not belong to plugin "%s".', $key, $pluginSlug));
			}
		}
		if (isset($this->connectors[$key])) {
			throw new \InvalidArgumentException(sprintf('Analytics connector key "%s" is already registered.', $key));
		}
		if ('analytics' !== $connector->consentCategory()) {
			throw new \InvalidArgumentException(sprintf('Analytics connector "%s" must use the analytics consent category.', $key));
		}
		if ('' === trim($connector->label())) {
			throw new \InvalidArgumentException(sprintf('Analytics connector "%s" must have a label.', $key));
		}
		$adapter = $connector->adapterIdentifier();
		if (!preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $adapter)) {
			throw new \InvalidArgumentException(sprintf('Analytics connector "%s" has an invalid adapter identifier.', $key));
		}

		$schema = $connector->settingSchema();
		$this->validateSettingSchema($key, $schema);
		$scriptUrl = $this->validatedHttpsUrl($connector->scriptUrl(), 'script URL', false);
		$dashboardUrl = $connector->dashboardUrl();
		if (null !== $dashboardUrl) {
			$dashboardUrl = $this->validatedHttpsUrl($dashboardUrl, 'dashboard URL', false);
		}
		$csp = $this->validateCspRequirements($key, $connector->cspRequirements(), $connector instanceof ClarityAnalyticsConnector);
		if (!$this->scriptUrlMatchesRules($scriptUrl, $csp['scriptSrc'])) {
			throw new \InvalidArgumentException(sprintf('Analytics connector "%s" script URL is not authorized by its scriptSrc requirements.', $key));
		}

		$this->connectors[$key] = $connector;
		$this->cspRequirements[$key] = $csp;
		$this->schemas[$key] = $schema;
		$this->adapters[$key] = $adapter;
		$this->scriptUrls[$key] = $scriptUrl;
		$this->dashboardUrls[$key] = $dashboardUrl;
	}

	/** @return array<string,AnalyticsConnectorInterface> Connectors enabled on the site, keyed by connector key. */
	public function forSite(Site $site): array
	{
		$enabledPlugins = $site->getEnabledPlugins();
		$connectors = [];
		foreach ($this->connectors as $key => $connector) {
			[$pluginSlug] = explode('.', $key, 2);
			if (in_array($pluginSlug, $enabledPlugins, true)) {
				$connectors[$key] = $connector;
			}
		}

		return $connectors;
	}

	/** @return array<string,AnalyticsConnectorInterface> */
	public function all(): array
	{
		return $this->connectors;
	}

	/** @return array<string,array<string,mixed>> */
	public function settingSchema(string $key): array
	{
		$this->connector($key);

		return $this->schemas[$key];
	}

	public function dashboardUrl(string $key): ?string
	{
		$this->connector($key);

		return $this->dashboardUrls[$key];
	}

	/**
	 * Validate stored config against the declared schema and implementation rules.
	 *
	 * @param array<array-key,mixed> $config
	 *
	 * @return array<string,scalar>
	 */
	public function validateConfig(string $key, array $config, bool $partial = false): array
	{
		$connector = $this->connector($key);
		$schema = $this->schemas[$key];
		$validated = [];

		foreach ($config as $field => $value) {
			if (!is_string($field) || !array_key_exists($field, $schema)) {
				throw new \InvalidArgumentException(sprintf('Unknown analytics setting "%s" for connector "%s".', (string) $field, $key));
			}
			$validated[$field] = $this->validateSettingValue($key, $field, $schema[$field], $value);
		}

		foreach ($schema as $field => $definition) {
			if (!$partial && ($definition['required'] ?? true) && !array_key_exists($field, $validated)) {
				throw new \InvalidArgumentException(sprintf('Analytics setting "%s" is required for connector "%s".', $field, $key));
			}
		}

		$connector->validateConfig($validated, $partial);

		return $validated;
	}

	/**
	 * Build a safe client descriptor using validated values only.
	 *
	 * @param array<string,mixed> $config
	 *
	 * @return array{adapter:string,scriptUrl:string,parameters:array<string,scalar>}
	 */
	public function clientDescriptor(string $key, array $config): array
	{
		$connector = $this->connector($key);
		$validatedConfig = $this->validateConfig($key, $config);
		$parameters = $this->validateClientDescriptor($key, $connector->buildClientDescriptor($validatedConfig));

		return [
			'adapter' => $this->adapters[$key],
			'scriptUrl' => $this->scriptUrls[$key],
			'parameters' => $parameters,
		];
	}

	/** @return array{scriptSrc:list<string>,connectSrc:list<string>,imgSrc:list<string>} */
	public function cspRequirements(string $key): array
	{
		if (!isset($this->cspRequirements[$key])) {
			$this->connector($key);
		}

		return $this->cspRequirements[$key];
	}

	private function connector(string $key): AnalyticsConnectorInterface
	{
		if (!isset($this->connectors[$key])) {
			throw new \InvalidArgumentException(sprintf('Analytics connector "%s" is not registered.', $key));
		}

		return $this->connectors[$key];
	}

	/** @param array<array-key,mixed> $schema */
	private function validateSettingSchema(string $key, array $schema): void
	{
		foreach ($schema as $field => $definition) {
			if (!is_string($field) || !$this->isSafeFieldName($field) || !is_array($definition)) {
				throw new \InvalidArgumentException(sprintf('Analytics connector "%s" has a malformed setting schema.', $key));
			}
			if (!isset($definition['type'], $definition['label']) || !is_string($definition['label']) || '' === trim($definition['label'])) {
				throw new \InvalidArgumentException(sprintf('Analytics connector "%s" setting "%s" requires a type and label.', $key, $field));
			}
			if (array_key_exists('required', $definition) && !is_bool($definition['required'])) {
				throw new \InvalidArgumentException(sprintf('Analytics connector "%s" setting "%s" has invalid required metadata.', $key, $field));
			}

			switch ($definition['type']) {
				case 'string':
					if (array_key_exists('maxLength', $definition) && (!is_int($definition['maxLength']) || $definition['maxLength'] < 1 || $definition['maxLength'] > self::MAX_STRING_SETTING_LENGTH)) {
						throw new \InvalidArgumentException(sprintf('Analytics connector "%s" setting "%s" has invalid maxLength.', $key, $field));
					}
					if (array_key_exists('pattern', $definition) && (!is_string($definition['pattern']) || false === @preg_match($definition['pattern'], ''))) {
						throw new \InvalidArgumentException(sprintf('Analytics connector "%s" setting "%s" has an invalid pattern.', $key, $field));
					}
					if (array_key_exists('options', $definition)) {
						throw new \InvalidArgumentException(sprintf('Analytics connector "%s" string setting "%s" cannot declare options.', $key, $field));
					}
					break;
				case 'boolean':
					if (array_key_exists('maxLength', $definition) || array_key_exists('pattern', $definition) || array_key_exists('options', $definition)) {
						throw new \InvalidArgumentException(sprintf('Analytics connector "%s" boolean setting "%s" has incompatible metadata.', $key, $field));
					}
					break;
				case 'enum':
					if (!array_key_exists('options', $definition) || !is_array($definition['options']) || [] === $definition['options']) {
						throw new \InvalidArgumentException(sprintf('Analytics connector "%s" enum setting "%s" requires options.', $key, $field));
					}
					foreach ($definition['options'] as $option => $label) {
						$this->validateEnumOption($key, $field, $option, $label);
					}
					if (array_key_exists('maxLength', $definition) || array_key_exists('pattern', $definition)) {
						throw new \InvalidArgumentException(sprintf('Analytics connector "%s" enum setting "%s" has incompatible metadata.', $key, $field));
					}
					break;
				default:
					throw new \InvalidArgumentException(sprintf('Analytics connector "%s" setting "%s" has an unsupported type.', $key, $field));
			}

			$allowedMetadata = match ($definition['type']) {
				'string' => ['type', 'label', 'required', 'maxLength', 'pattern'],
				'boolean' => ['type', 'label', 'required'],
				'enum' => ['type', 'label', 'required', 'options'],
				default => throw new \InvalidArgumentException(sprintf('Analytics connector "%s" setting "%s" has an unsupported type.', $key, $field)),
			};
			if ([] !== array_diff(array_keys($definition), $allowedMetadata)) {
				throw new \InvalidArgumentException(sprintf('Analytics connector "%s" setting "%s" has unknown schema metadata.', $key, $field));
			}
		}
	}

	/** @param array{type:'string'|'boolean'|'enum',label:string,required?:bool,maxLength?:int,pattern?:string,options?:array<string,string>} $definition */
	private function validateSettingValue(string $key, string $field, array $definition, mixed $value): string|bool
	{
		switch ($definition['type']) {
			case 'string':
				if (!is_string($value)) {
					throw new \InvalidArgumentException(sprintf('Analytics setting "%s" for connector "%s" must be a string.', $field, $key));
				}
				$this->assertSafeScalar($value, sprintf('setting "%s"', $field));
				if (mb_strlen($value, 'UTF-8') > ($definition['maxLength'] ?? self::MAX_STRING_SETTING_LENGTH)) {
					throw new \InvalidArgumentException(sprintf('Analytics setting "%s" for connector "%s" exceeds its maximum length.', $field, $key));
				}
				if (isset($definition['pattern']) && 1 !== preg_match($definition['pattern'], $value)) {
					throw new \InvalidArgumentException(sprintf('Analytics setting "%s" for connector "%s" does not match its required pattern.', $field, $key));
				}

				return $value;
			case 'boolean':
				if (!is_bool($value)) {
					throw new \InvalidArgumentException(sprintf('Analytics setting "%s" for connector "%s" must be a boolean.', $field, $key));
				}

				return $value;
			case 'enum':
				if (!is_string($value) || !array_key_exists($value, $definition['options'])) {
					throw new \InvalidArgumentException(sprintf('Analytics setting "%s" for connector "%s" must be one of its declared options.', $field, $key));
				}
				$this->assertSafeScalar($value, sprintf('setting "%s"', $field));

				return $value;
		}
	}

	private function isSafeFieldName(string $name): bool
	{
		if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $name)) {
			return false;
		}

		$normalized = strtolower(str_replace('_', '', $name));
		if (in_array($normalized, ['constructor', 'prototype', 'proto', 'tostring', 'valueof', 'hasownproperty', 'isprototypeof', 'propertyisenumerable', 'tolocalestring'], true)) {
			return false;
		}
		foreach (['adapter', 'url', 'uri', 'script', 'html', 'markup', 'javascript', 'code', 'secret', 'password', 'credential', 'token', 'authorization', 'apikey', 'accesskey', 'privatekey', 'sharedkey'] as $reserved) {
			if (str_contains($normalized, $reserved)) {
				return false;
			}
		}

		return true;
	}

	private function assertSafeScalar(string|int|float|bool $value, string $description): void
	{
		if (is_float($value) && !is_finite($value)) {
			throw new \InvalidArgumentException(sprintf('Analytics %s must be finite.', $description));
		}
		if (!is_string($value)) {
			return;
		}
		if (1 !== preg_match('//u', $value) || preg_match('/[\x00-\x1F\x7F<>]/u', $value)) {
			throw new \InvalidArgumentException(sprintf('Analytics %s must not contain invalid text, controls, or markup.', $description));
		}
		if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) || preg_match('#^(?:/|[a-z0-9.-]+\.[a-z]{2,}(?::[0-9]+)?(?:/|$))#i', $value)) {
			throw new \InvalidArgumentException(sprintf('Analytics %s must not contain a URL.', $description));
		}
	}

	/** @return array{scriptSrc:list<string>,connectSrc:list<string>,imgSrc:list<string>} */
	private function validateCspRequirements(string $key, mixed $requirements, bool $allowRootScriptSource): array
	{
		if (!is_array($requirements) || 3 !== count($requirements) || !isset($requirements['scriptSrc'], $requirements['connectSrc'], $requirements['imgSrc'])) {
			throw new \InvalidArgumentException(sprintf('Analytics connector "%s" must declare scriptSrc, connectSrc, and imgSrc CSP requirements.', $key));
		}

		$scriptSrc = $this->validateCspDirective($key, 'scriptSrc', $requirements['scriptSrc'], false, $allowRootScriptSource);
		$connectSrc = $this->validateCspDirective($key, 'connectSrc', $requirements['connectSrc'], true);
		$imgSrc = $this->validateCspDirective($key, 'imgSrc', $requirements['imgSrc'], true);
		if ([] === $scriptSrc) {
			throw new \InvalidArgumentException(sprintf('Analytics connector "%s" must declare at least one scriptSrc source.', $key));
		}

		return ['scriptSrc' => $scriptSrc, 'connectSrc' => $connectSrc, 'imgSrc' => $imgSrc];
	}

	private function validatedHttpsUrl(mixed $url, string $description, bool $allowWildcardHost): string
	{
		if (!is_string($url) || '' === $url || preg_match('/[\s\x00-\x1F\x7F;,]/', $url) || preg_match('/%(?![a-f0-9]{2})/i', $url) || str_contains($url, '\\') || str_contains($url, '*') && !$allowWildcardHost) {
			throw new \InvalidArgumentException(sprintf('Analytics %s must be a valid HTTPS URL.', $description));
		}
		try {
			$parts = parse_url($url);
		} catch (\ValueError) {
			$parts = false;
		}
		if (!is_array($parts) || 'https' !== strtolower((string) ($parts['scheme'] ?? '')) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
			throw new \InvalidArgumentException(sprintf('Analytics %s must be credential-free HTTPS without query or fragment.', $description));
		}

		$host = strtolower($parts['host']);
		$wildcardHost = $allowWildcardHost && str_starts_with($host, '*.');
		if ($wildcardHost) {
			$host = substr($host, 2);
		}
		if (
			str_contains($host, '*')
			|| false !== filter_var($host, FILTER_VALIDATE_IP)
			|| false === filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
			|| ($wildcardHost && count(explode('.', $host)) < 2)
		) {
			throw new \InvalidArgumentException(sprintf('Analytics %s must declare a valid HTTPS host.', $description));
		}
		if (isset($parts['port']) && false === filter_var((string) $parts['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65_535]])) {
			throw new \InvalidArgumentException(sprintf('Analytics %s declares an invalid port.', $description));
		}
		$path = $parts['path'] ?? '';
		$decodedPath = rawurldecode($path);
		if (str_contains($path, '\\') || str_contains($decodedPath, '//') || preg_match('/[\x00-\x1F\x7F;,]/', $decodedPath) || preg_match('#(?:^|/)\.\.?(/|$)#', $decodedPath)) {
			throw new \InvalidArgumentException(sprintf('Analytics %s declares an unsafe path.', $description));
		}
		if (str_contains($url, '*') && (!$allowWildcardHost || !str_starts_with(strtolower($parts['host']), '*.') || 1 !== substr_count($url, '*'))) {
			throw new \InvalidArgumentException(sprintf('Analytics %s uses an unsupported wildcard.', $description));
		}

		return $url;
	}

	/** @return array<string,scalar> */
	private function validateClientDescriptor(string $key, mixed $descriptor): array
	{
		if (!is_array($descriptor) || 3 !== count($descriptor) || !array_key_exists('adapter', $descriptor) || !array_key_exists('scriptUrl', $descriptor) || !array_key_exists('parameters', $descriptor)) {
			throw new \InvalidArgumentException(sprintf('Analytics connector "%s" returned a malformed client descriptor.', $key));
		}
		if (!is_string($descriptor['adapter']) || !is_string($descriptor['scriptUrl']) || $descriptor['adapter'] !== $this->adapters[$key] || $descriptor['scriptUrl'] !== $this->scriptUrls[$key]) {
			throw new \InvalidArgumentException(sprintf('Analytics connector "%s" descriptor must use its code-owned adapter and script URL.', $key));
		}
		$this->validatedHttpsUrl($descriptor['scriptUrl'], 'descriptor script URL', false);
		if (!is_array($descriptor['parameters'])) {
			throw new \InvalidArgumentException(sprintf('Analytics connector "%s" descriptor parameters must be an object-like array.', $key));
		}
		foreach ($descriptor['parameters'] as $name => $value) {
			if (!is_string($name) || !$this->isSafeFieldName($name) || !is_scalar($value)) {
				throw new \InvalidArgumentException(sprintf('Analytics connector "%s" returned an invalid client parameter.', $key));
			}
			$this->assertSafeScalar($value, sprintf('client parameter "%s"', $name));
		}

		return $descriptor['parameters'];
	}

	private function validateEnumOption(string $key, string $field, mixed $option, mixed $label): void
	{
		if (!is_string($option) || '' === $option || !is_string($label) || '' === trim($label)) {
			throw new \InvalidArgumentException(sprintf('Analytics connector "%s" enum setting "%s" has invalid options.', $key, $field));
		}
		$this->assertSafeScalar($option, sprintf('enum option for setting "%s"', $field));
	}

	/** @return list<string> */
	private function validateCspDirective(string $key, string $directive, mixed $sources, bool $allowWildcardHost, bool $allowRootPath = false): array
	{
		if (!is_array($sources) || !array_is_list($sources)) {
			throw new \InvalidArgumentException(sprintf('Analytics connector "%s" CSP directive "%s" must be a list.', $key, $directive));
		}
		$validated = [];
		foreach ($sources as $source) {
			if (!is_string($source)) {
				throw new \InvalidArgumentException(sprintf('Analytics connector "%s" CSP source entries must be strings.', $key));
			}
			$this->validatedHttpsUrl($source, sprintf('%s CSP source', $directive), $allowWildcardHost);
			if ('scriptSrc' === $directive) {
				$path = parse_url($source, PHP_URL_PATH);
				if (!is_string($path) || '' === $path || ('/' === $path && !$allowRootPath)) {
					throw new \InvalidArgumentException(sprintf('Analytics connector "%s" scriptSrc sources must be pinned to a non-root path.', $key));
				}
			}
			$validated[] = $source;
		}

		return $validated;
	}

	/** @param list<string> $rules */
	private function scriptUrlMatchesRules(string $scriptUrl, array $rules): bool
	{
		$script = parse_url($scriptUrl);
		if (!is_array($script)) {
			return false;
		}
		$scriptPath = $script['path'] ?? '';
		foreach ($rules as $rule) {
			$source = parse_url($rule);
			if (!is_array($source) || str_contains((string) ($source['host'] ?? ''), '*')) {
				continue;
			}
			if (strtolower($source['host']) !== strtolower($script['host']) || ($source['port'] ?? 443) !== ($script['port'] ?? 443)) {
				continue;
			}
			$rulePath = $source['path'] ?? '';
			if ('' === $rulePath || (str_ends_with($rulePath, '/') ? str_starts_with($scriptPath, $rulePath) : $scriptPath === $rulePath)) {
				return true;
			}
		}

		return false;
	}
}
