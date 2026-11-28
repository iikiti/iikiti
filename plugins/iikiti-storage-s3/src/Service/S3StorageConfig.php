<?php

declare(strict_types=1);

namespace iikiti\Extension\StorageS3\Service;

use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Secrets\SecretsApi;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Immutable S3-compatible storage configuration value object supporting
 * AWS S3, Cloudflare R2, MinIO, DigitalOcean Spaces, and GCS S3-interop presets.
 */
#[Exclude]
final readonly class S3StorageConfig
{
	public const PLUGIN_SLUG = 'iikiti-storage-s3';
	public const SECRET_KEY_PROPERTY = 'plugin.iikiti-storage-s3.secret_key';

	public const PROVIDER_S3 = 's3';
	public const PROVIDER_R2 = 'r2';
	public const PROVIDER_GCS = 'gcs';

	/** @var list<string> */
	public const ALLOWED_PROVIDERS = [
		self::PROVIDER_S3,
		self::PROVIDER_R2,
		self::PROVIDER_GCS,
	];

	public const GCS_INTEROP_ENDPOINT = 'https://storage.googleapis.com';
	public const ALLOW_PRIVATE_ENDPOINTS_ENV = 'ASSET_S3_ALLOW_PRIVATE_ENDPOINTS';

	public string $provider;
	public string $endpoint;
	public string $region;
	public string $bucket;
	public string $accessKey;
	public string $secretKey;
	public string $prefix;
	public bool $pathStyle;
	public string $cdnUrl;

	public function __construct(
		string $provider = self::PROVIDER_S3,
		string $endpoint = '',
		string $region = 'us-east-1',
		string $bucket = '',
		string $accessKey = '',
		string $secretKey = '',
		string $prefix = '',
		bool $pathStyle = false,
		string $cdnUrl = '',
		?bool $allowPrivateEndpoints = null,
	) {
		$normalizedProvider = strtolower(trim($provider));
		if (!in_array($normalizedProvider, self::ALLOWED_PROVIDERS, true)) {
			throw new \InvalidArgumentException(sprintf('Unsupported object storage provider "%s".', $provider));
		}

		$normalizedEndpoint = rtrim(trim($endpoint), '/');
		if ('' !== $normalizedEndpoint) {
			self::assertSafeEndpointUrl($normalizedEndpoint, 'endpoint', $allowPrivateEndpoints, false);
		}

		$normalizedCdnUrl = rtrim(trim($cdnUrl), '/');
		if ('' !== $normalizedCdnUrl) {
			self::assertSafeEndpointUrl($normalizedCdnUrl, 'cdnUrl', $allowPrivateEndpoints, false);
		}

		$this->provider = $normalizedProvider;
		$this->endpoint = $normalizedEndpoint;
		$this->region = trim($region);
		$this->bucket = trim($bucket);
		$this->accessKey = trim($accessKey);
		$this->secretKey = trim($secretKey);
		$this->prefix = trim(str_replace('\\', '/', trim($prefix)), '/');
		$this->pathStyle = $pathStyle;
		$this->cdnUrl = $normalizedCdnUrl;
	}

	/**
	 * Builds configuration from application plugin settings + {@see SecretsApi}
	 * with environment variable fallback.
	 *
	 * @param array{
	 *     provider?: string,
	 *     endpoint?: string,
	 *     region?: string,
	 *     bucket?: string,
	 *     accessKey?: string,
	 *     secretKey?: string,
	 *     prefix?: string,
	 *     pathStyle?: bool,
	 *     cdnUrl?: string,
	 * } $envDefaults
	 */
	public static function fromApplicationAndEnv(
		?Application $application,
		?SecretsApi $secretsApi,
		array $envDefaults = [],
		?string $providerOverride = null,
	): self {
		$stored = null !== $application ?
			(array) $application->getConfiguration()->getPluginConfiguration(self::PLUGIN_SLUG) :
			[];

		$provider = $providerOverride ??
			self::nonEmptyString($stored['provider'] ?? null) ??
			self::nonEmptyString($envDefaults['provider'] ?? null) ??
			self::PROVIDER_S3;

		$endpoint = self::nonEmptyString($stored['endpoint'] ?? null) ??
			self::nonEmptyString($envDefaults['endpoint'] ?? null) ??
			'';

		$region = self::nonEmptyString($stored['region'] ?? null) ??
			self::nonEmptyString($envDefaults['region'] ?? null) ??
			'';

		$bucket = self::nonEmptyString($stored['bucket'] ?? null) ??
			self::nonEmptyString($envDefaults['bucket'] ?? null) ??
			'';

		$accessKey = self::nonEmptyString($stored['accessKey'] ?? null) ??
			self::nonEmptyString($envDefaults['accessKey'] ?? null) ??
			'';

		$prefix = array_key_exists('prefix', $stored) && is_string($stored['prefix']) ?
			$stored['prefix'] :
			($envDefaults['prefix'] ?? '');

		$pathStyle = array_key_exists('pathStyle', $stored) && is_bool($stored['pathStyle']) ?
			$stored['pathStyle'] :
			(bool) ($envDefaults['pathStyle'] ?? false);

		$cdnUrl = array_key_exists('cdnUrl', $stored) && is_string($stored['cdnUrl']) ?
			$stored['cdnUrl'] :
			($envDefaults['cdnUrl'] ?? '');

		$secretKey = '';
		if (null !== $application && null !== $secretsApi) {
			try {
				$storedSecret = $secretsApi->get($application, self::SECRET_KEY_PROPERTY);
				if (is_string($storedSecret) && '' !== trim($storedSecret)) {
					$secretKey = trim($storedSecret);
				}
			} catch (\Throwable) {
				// Fall back to environment secret when application secrets keyring is unavailable.
			}
		}

		if ('' === $secretKey) {
			$secretKey = self::nonEmptyString($envDefaults['secretKey'] ?? null) ?? '';
		}

		$config = new self(
			provider: $provider,
			endpoint: $endpoint,
			region: $region,
			bucket: $bucket,
			accessKey: $accessKey,
			secretKey: $secretKey,
			prefix: $prefix,
			pathStyle: $pathStyle,
			cdnUrl: $cdnUrl,
		);

		return null !== $providerOverride ? $config->withProviderPreset($providerOverride) : $config->withProviderPreset($config->provider);
	}

	/**
	 * Returns a copy with provider-specific endpoint, region, and path-style defaults applied.
	 */
	public function withProviderPreset(string $provider): self
	{
		$normalized = strtolower(trim($provider));
		if (!in_array($normalized, self::ALLOWED_PROVIDERS, true)) {
			throw new \InvalidArgumentException(sprintf('Unsupported object storage provider "%s".', $provider));
		}

		return match ($normalized) {
			self::PROVIDER_R2 => new self(
				provider: self::PROVIDER_R2,
				endpoint: $this->endpoint,
				region: '' === $this->region || 'us-east-1' === $this->region ? 'auto' : $this->region,
				bucket: $this->bucket,
				accessKey: $this->accessKey,
				secretKey: $this->secretKey,
				prefix: $this->prefix,
				pathStyle: true,
				cdnUrl: $this->cdnUrl,
			),
			self::PROVIDER_GCS => new self(
				provider: self::PROVIDER_GCS,
				endpoint: '' !== $this->endpoint ? $this->endpoint : self::GCS_INTEROP_ENDPOINT,
				region: '' === $this->region || 'us-east-1' === $this->region ? 'auto' : $this->region,
				bucket: $this->bucket,
				accessKey: $this->accessKey,
				secretKey: $this->secretKey,
				prefix: $this->prefix,
				pathStyle: true,
				cdnUrl: $this->cdnUrl,
			),
			default => new self(
				provider: self::PROVIDER_S3,
				endpoint: $this->endpoint,
				region: '' !== $this->region ? $this->region : 'us-east-1',
				bucket: $this->bucket,
				accessKey: $this->accessKey,
				secretKey: $this->secretKey,
				prefix: $this->prefix,
				pathStyle: $this->pathStyle,
				cdnUrl: $this->cdnUrl,
			),
		};
	}

	public function isConfigured(): bool
	{
		return '' !== $this->bucket &&
			'' !== $this->accessKey &&
			'' !== $this->secretKey &&
			'' !== $this->resolvedEndpoint();
	}

	public function resolvedRegion(): string
	{
		if ('' !== $this->region) {
			return $this->region;
		}

		return self::PROVIDER_S3 === $this->provider ? 'us-east-1' : 'auto';
	}

	public function resolvedEndpoint(): string
	{
		if ('' !== $this->endpoint) {
			return $this->endpoint;
		}

		return match ($this->provider) {
			self::PROVIDER_GCS => self::GCS_INTEROP_ENDPOINT,
			self::PROVIDER_S3 => sprintf('https://s3.%s.amazonaws.com', $this->resolvedRegion()),
			default => '',
		};
	}

	public function prefixedKey(string $objectKey): string
	{
		$normalizedKey = ltrim($objectKey, '/');

		return '' !== $this->prefix ? $this->prefix.'/'.$normalizedKey : $normalizedKey;
	}

	public function buildObjectUrl(string $objectKey): string
	{
		$endpoint = $this->resolvedEndpoint();
		if ('' === $endpoint || '' === $this->bucket) {
			throw new \LogicException('Object storage endpoint and bucket must be configured before building object URLs.');
		}

		$encodedKey = $this->encodePath($this->prefixedKey($objectKey));
		if ($this->pathStyle) {
			return sprintf('%s/%s/%s', $endpoint, rawurlencode($this->bucket), $encodedKey);
		}

		return sprintf('%s/%s', $this->buildVirtualHostedOrigin($endpoint), $encodedKey);
	}

	public function buildBucketUrl(): string
	{
		$endpoint = $this->resolvedEndpoint();
		if ('' === $endpoint || '' === $this->bucket) {
			throw new \LogicException('Object storage endpoint and bucket must be configured before building bucket URLs.');
		}

		if ($this->pathStyle) {
			return sprintf('%s/%s', $endpoint, rawurlencode($this->bucket));
		}

		return $this->buildVirtualHostedOrigin($endpoint).'/';
	}

	public function buildPublicUrl(string $objectKey): ?string
	{
		if ('' === $this->cdnUrl) {
			return null;
		}

		return sprintf('%s/%s', $this->cdnUrl, $this->encodePath($this->prefixedKey($objectKey)));
	}

	/**
	 * Returns a non-sensitive array safe for admin API responses.
	 *
	 * @return array{
	 *     provider: string,
	 *     endpoint: string,
	 *     resolvedEndpoint: string,
	 *     region: string,
	 *     bucket: string,
	 *     accessKey: string,
	 *     hasSecretKey: bool,
	 *     secretKeyMasked: string,
	 *     prefix: string,
	 *     pathStyle: bool,
	 *     cdnUrl: string,
	 *     configured: bool,
	 * }
	 */
	public function toSanitizedArray(): array
	{
		return [
			'provider' => $this->provider,
			'endpoint' => $this->endpoint,
			'resolvedEndpoint' => $this->resolvedEndpoint(),
			'region' => $this->resolvedRegion(),
			'bucket' => $this->bucket,
			'accessKey' => $this->accessKey,
			'hasSecretKey' => '' !== $this->secretKey,
			'secretKeyMasked' => '' !== $this->secretKey ? '********' : '',
			'prefix' => $this->prefix,
			'pathStyle' => $this->pathStyle,
			'cdnUrl' => $this->cdnUrl,
			'configured' => $this->isConfigured(),
		];
	}

	private function buildVirtualHostedOrigin(string $endpoint): string
	{
		$parts = parse_url($endpoint);
		if (!is_array($parts)) {
			throw new \LogicException('Configured object storage endpoint is not a valid URL.');
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		$host = strtolower((string) ($parts['host'] ?? ''));
		if (!in_array($scheme, ['http', 'https'], true) || '' === $host) {
			throw new \LogicException('Configured object storage endpoint must use an HTTP(S) URL with a host.');
		}

		$bucketPrefix = strtolower($this->bucket).'.';
		$virtualHost = str_starts_with($host, $bucketPrefix) ? $host : $bucketPrefix.$host;
		$port = $parts['port'] ?? null;
		$portPart = is_int($port) ? ':'.$port : '';

		return sprintf('%s://%s%s', $scheme, $virtualHost, $portPart);
	}

	private function encodePath(string $path): string
	{
		$segments = explode('/', ltrim($path, '/'));

		return implode('/', array_map('rawurlencode', $segments));
	}

	public static function allowsPrivateEndpoints(): bool
	{
		$raw = $_ENV[self::ALLOW_PRIVATE_ENDPOINTS_ENV]
			?? $_SERVER[self::ALLOW_PRIVATE_ENDPOINTS_ENV]
			?? getenv(self::ALLOW_PRIVATE_ENDPOINTS_ENV);

		if (is_bool($raw)) {
			return $raw;
		}
		if (!is_string($raw)) {
			return false;
		}

		return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
	}

	/**
	 * Validates that an HTTP(S) S3 endpoint or CDN URL does not target loopback,
	 * link-local, private, or reserved IP addresses or internal hostnames unless
	 * explicitly permitted via `ASSET_S3_ALLOW_PRIVATE_ENDPOINTS=true`.
	 */
	public static function assertSafeEndpointUrl(
		string $url,
		string $field = 'endpoint',
		?bool $allowPrivateEndpoints = null,
		bool $resolveDns = true,
	): string {
		$trimmed = rtrim(trim($url), '/');
		if ('' === $trimmed || 1 === preg_match('/[\x00-\x1f\x7f]/', $trimmed)) {
			throw new \InvalidArgumentException(sprintf('Field "%s" must be a valid HTTP(S) URL.', $field));
		}

		$parts = parse_url($trimmed);
		if (!is_array($parts) || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
			throw new \InvalidArgumentException(sprintf('Field "%s" must be a valid HTTP(S) URL without embedded credentials.', $field));
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		$rawHost = strtolower(rtrim(trim((string) ($parts['host'] ?? '')), '.'));
		if (!in_array($scheme, ['http', 'https'], true) || '' === $rawHost) {
			throw new \InvalidArgumentException(sprintf('Field "%s" must be a valid HTTP(S) URL.', $field));
		}

		$allowPrivate = $allowPrivateEndpoints ?? self::allowsPrivateEndpoints();
		if ($allowPrivate) {
			return $trimmed;
		}

		$host = str_starts_with($rawHost, '[') && str_ends_with($rawHost, ']')
			? substr($rawHost, 1, -1)
			: $rawHost;

		if (
			'localhost' === $host
			|| str_ends_with($host, '.localhost')
			|| str_ends_with($host, '.local')
			|| str_ends_with($host, '.internal')
			|| str_ends_with($host, '.localdomain')
			|| str_ends_with($host, '.home.arpa')
		) {
			throw new \InvalidArgumentException(sprintf(
				'Field "%s" cannot target localhost or internal hostnames unless %s=true is set.',
				$field,
				self::ALLOW_PRIVATE_ENDPOINTS_ENV,
			));
		}

		if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
			if (!self::isPublicIp($host)) {
				throw new \InvalidArgumentException(sprintf(
					'Field "%s" cannot target private, loopback, link-local, or reserved IP addresses unless %s=true is set.',
					$field,
					self::ALLOW_PRIVATE_ENDPOINTS_ENV,
				));
			}

			return $trimmed;
		}

		if ($resolveDns) {
			$resolvedIps = [];
			$records = @dns_get_record($host, DNS_A | DNS_AAAA);
			if (is_array($records)) {
				foreach ($records as $record) {
					if (isset($record['ip']) && is_string($record['ip'])) {
						$resolvedIps[] = $record['ip'];
					}
					if (isset($record['ipv6']) && is_string($record['ipv6'])) {
						$resolvedIps[] = $record['ipv6'];
					}
				}
			}
			if ([] === $resolvedIps) {
				$ipv4List = @gethostbynamel($host);
				if (is_array($ipv4List)) {
					$resolvedIps = $ipv4List;
				}
			}
			foreach ($resolvedIps as $ip) {
				if (!self::isPublicIp($ip)) {
					throw new \InvalidArgumentException(sprintf(
						'Field "%s" resolves to a private, loopback, link-local, or reserved IP address.',
						$field,
					));
				}
			}
		}

		return $trimmed;
	}

	private static function isPublicIp(string $ip): bool
	{
		$normalized = strtolower(trim($ip));
		if ('' === $normalized) {
			return false;
		}

		$packed = @inet_pton($normalized);
		if (false === $packed) {
			return false;
		}

		// Unpack any 16-byte IPv4-mapped IPv6 address (::ffff:0:0/96), including hex
		// and expanded forms (e.g. ::ffff:7f00:1, ::ffff:a9fe:a9fe, 0:0:0:0:0:ffff:127.0.0.1).
		if (16 === strlen($packed) && "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" === substr($packed, 0, 12)) {
			$ipv4Bytes = substr($packed, 12, 4);
			$ipv4 = inet_ntop($ipv4Bytes);
			if (false === $ipv4) {
				return false;
			}
			$normalized = $ipv4;
			$packed = $ipv4Bytes;
		}

		if (4 === strlen($packed)) {
			// Explicitly reject RFC 6598 Carrier-Grade NAT (100.64.0.0/10).
			$unpacked = unpack('N', $packed);
			if (false === $unpacked || !isset($unpacked[1])) {
				return false;
			}
			$ipLong = (int) $unpacked[1];
			if (($ipLong & 0xFFC00000) === 0x64400000) {
				return false;
			}
		}

		return false !== filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
	}

	private static function nonEmptyString(mixed $value): ?string
	{
		if (!is_string($value)) {
			return null;
		}
		$trimmed = trim($value);

		return '' !== $trimmed ? $trimmed : null;
	}
}
