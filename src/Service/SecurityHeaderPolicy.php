<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Renders security header values and resolves the effective on/off mode.
 *
 * Headers are emitted only when the effective mode is `on` AND the request is
 * secure and the response is successful. CSP values may contain the
 * `__IIKITI_CSP_NONCE__` placeholder, which is replaced with the per-request
 * `nonce-<base64>` source at render time.
 */
final class SecurityHeaderPolicy
{
	public const ENV_MODE_DEFAULT = 'default';
	public const ENV_MODE_OFF = 'off';
	public const SITE_MODE_DEFAULT = 'default';
	public const SITE_MODE_FORCED_OFF = 'forced_off';
	public const SITE_MODE_FORCED_ON = 'forced_on';

	public const HEADER_CSP = 'Content-Security-Policy';
	public const HEADER_CSP_REPORT_ONLY = 'Content-Security-Policy-Report-Only';
	public const HEADER_HSTS = 'Strict-Transport-Security';
	public const HEADER_FRAME_OPTIONS = 'X-Frame-Options';
	public const HEADER_CONTENT_TYPE_OPTIONS = 'X-Content-Type-Options';
	public const HEADER_REFERRER_POLICY = 'Referrer-Policy';
	public const HEADER_PERMISSIONS_POLICY = 'Permissions-Policy';
	public const HEADER_COOP = 'Cross-Origin-Opener-Policy';
	public const HEADER_COEP = 'Cross-Origin-Embedder-Policy';
	public const HEADER_CORP = 'Cross-Origin-Resource-Policy';

	private const NONCE_PLACEHOLDER = '__IIKITI_CSP_NONCE__';

	/** @var list<string> */
	private array $knownHeaders = [
		self::HEADER_CSP,
		self::HEADER_CSP_REPORT_ONLY,
		self::HEADER_HSTS,
		self::HEADER_FRAME_OPTIONS,
		self::HEADER_CONTENT_TYPE_OPTIONS,
		self::HEADER_REFERRER_POLICY,
		self::HEADER_PERMISSIONS_POLICY,
		self::HEADER_COOP,
		self::HEADER_COEP,
		self::HEADER_CORP,
	];

	/** @var array<string> */
	#[Autowire('%mcp_allowed_hosts%')]
	private array $mcpAllowedHosts = [];

	/**
	 * @param iterable<CspDirectiveContributorInterface> $cspContributors
	 */
	public function __construct(
		private readonly string $environment,
		#[AutowireIterator('iikiti.security.csp_directive_contributor')]
		private readonly iterable $cspContributors = [],
	) {
	}

	public function environment(): string
	{
		return $this->environment;
	}

	/**
	 * Resolve the effective header mode.
	 *
	 * @param bool $isSecure     Secure request (HTTPS)
	 * @param bool $isSuccessful Successful response status (2xx/3xx)
	 */
	public function effectiveMode(string $envMode, string $siteMode, bool $isSecure, bool $isSuccessful): string
	{
		if (self::ENV_MODE_OFF === $this->normalizeEnvMode($envMode)) {
			return 'off';
		}
		if (self::SITE_MODE_FORCED_OFF === $siteMode) {
			return 'off';
		}
		if (self::SITE_MODE_FORCED_ON === $siteMode) {
			return 'on';
		}

		return ($isSecure && $isSuccessful) ? 'on' : 'off';
	}

	/**
	 * Normalize the env var value: blank/empty/'default'/'true' => default,
	 * anything else => off (only 'off' is forced off).
	 */
	public function normalizeEnvMode(string $envMode): string
	{
		$value = strtolower(trim((string) $envMode));
		if ('' === $value || 'default' === $value || 'true' === $value || '1' === $value) {
			return self::ENV_MODE_DEFAULT;
		}
		if ('off' === $value || 'false' === $value || '0' === $value) {
			return self::ENV_MODE_OFF;
		}

		return self::ENV_MODE_DEFAULT;
	}

	/**
	 * @param list<array{name:string,mode:string,value:string}> $configuredHeaders
	 *
	 * @return array<string,string> Header name => header value (only headers to emit)
	 */
	public function renderHeaders(array $configuredHeaders, string $mode, ?string $nonce = null): array
	{
		if ('off' === $mode) {
			return [];
		}

		$headers = $this->defaultHeadersForEnvironment();
		if ([] === $configuredHeaders) {
			foreach ($headers as $name => $value) {
				$headers[$name] = $this->substituteNonce($value, $nonce, $name);
			}

			return $headers;
		}

		$headers = [];
		foreach ($configuredHeaders as $header) {
			$name = $header['name'];
			if ('' === $name || !in_array($name, $this->knownHeaders, true)) {
				continue;
			}
			$modeValue = $header['mode'];
			$value = $header['value'];
			if ('off' === $modeValue || '' === $value) {
				continue;
			}
			$headers[$name] = $this->substituteNonce($value, $nonce, $name);
		}

		return $headers;
	}

	/**
	 * @return array<string,string> Default header name => header value
	 */
	private function defaultHeadersForEnvironment(): array
	{
		$prod = [
			self::HEADER_CSP => $this->applyCspContributors("default-src 'self'; script-src 'self' __IIKITI_CSP_NONCE__; style-src 'self' __IIKITI_CSP_NONCE__; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; connect-src 'self' {$this->connectSrc()}"),
			self::HEADER_HSTS => 'max-age=31536000; includeSubDomains; preload',
			self::HEADER_FRAME_OPTIONS => 'DENY',
			self::HEADER_CONTENT_TYPE_OPTIONS => 'nosniff',
			self::HEADER_REFERRER_POLICY => 'strict-origin-when-cross-origin',
			self::HEADER_PERMISSIONS_POLICY => 'camera=(), microphone=(), geolocation=(), payment=()',
			self::HEADER_COOP => 'same-origin',
			self::HEADER_COEP => 'unsafe-none',
			self::HEADER_CORP => 'same-origin',
		];
		$dev = [
			self::HEADER_CSP => $this->applyCspContributors("default-src 'self' 'unsafe-inline' 'unsafe-eval'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; connect-src 'self' {$this->connectSrc()}"),
			self::HEADER_HSTS => 'max-age=0',
			self::HEADER_FRAME_OPTIONS => 'SAMEORIGIN',
			self::HEADER_CONTENT_TYPE_OPTIONS => 'nosniff',
			self::HEADER_REFERRER_POLICY => 'strict-origin-when-cross-origin',
			self::HEADER_PERMISSIONS_POLICY => 'camera=(), microphone=(), geolocation=(), payment=()',
			self::HEADER_COOP => 'same-origin',
			self::HEADER_COEP => 'unsafe-none',
			self::HEADER_CORP => 'same-origin',
		];

		return 'prod' === $this->environment ? $prod : $dev;
	}

	private function applyCspContributors(string $basePolicy): string
	{
		$directives = [];
		$order = [];
		foreach (explode(';', $basePolicy) as $segment) {
			$trimmed = trim($segment);
			if ('' === $trimmed) {
				continue;
			}
			$parts = preg_split('/\s+/', $trimmed) ?: [];
			$name = strtolower(array_shift($parts) ?? '');
			if ('' === $name) {
				continue;
			}
			$order[] = $name;
			$directives[$name] = $parts;
		}

		foreach ($this->cspContributors as $contributor) {
			foreach ($contributor->getDirectives($this->environment) as $directive => $values) {
				$name = strtolower(trim((string) $directive));
				if (1 !== preg_match('/^[a-z0-9-]+$/D', $name)) {
					continue;
				}
				if (!isset($directives[$name])) {
					$order[] = $name;
					$directives[$name] = [];
				}
				foreach ($values as $value) {
					$token = trim((string) $value);
					if ('' === $token || 1 === preg_match('/[;,\r\n]/', $token)) {
						continue;
					}
					if (!in_array($token, $directives[$name], true)) {
						$directives[$name][] = $token;
					}
				}
			}
		}

		$rendered = [];
		foreach (array_values(array_unique($order)) as $name) {
			$values = $directives[$name];
			$rendered[] = [] === $values ? $name : $name.' '.implode(' ', $values);
		}

		return implode('; ', $rendered);
	}

	private function connectSrc(): string
	{
		$hosts = $this->mcpAllowedHosts;
		$parts = array_map(static fn (string $h): string => "'".trim($h)."'", $hosts);

		return implode(' ', $parts);
	}

	private function substituteNonce(string $value, ?string $nonce, string $headerName): string
	{
		if (null === $nonce || !str_contains($value, self::NONCE_PLACEHOLDER)) {
			return $value;
		}
		if (self::HEADER_CSP !== $headerName && self::HEADER_CSP_REPORT_ONLY !== $headerName) {
			return $value;
		}

		return str_replace(self::NONCE_PLACEHOLDER, "nonce-{$nonce}", $value);
	}

	#[\Override]
	public function __toString(): string
	{
		return $this->environment;
	}
}
