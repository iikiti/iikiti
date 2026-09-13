<?php

namespace iikiti\CMS\Plugin;

use iikiti\CMS\Plugin\Exception\PluginException;
use iikiti\CMS\Plugin\Exception\PluginNotFoundException;
use iikiti\CMS\Plugin\Exception\SecurityViolationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches plugin packages from the configured store and verifies their
 * integrity, authenticity and review state before they are installed.
 *
 * Only the iikiti store may be used on production servers. Third-party store
 * URLs are accepted in development/testing only and still route through the
 * iikiti store infrastructure for review and distribution.
 */
class PluginDownloader
{
	public const API_PREFIX = '/api/v1/plugins';

	public function __construct(
		private readonly HttpClientInterface $httpClient,
		private readonly PluginValidator $validator,
		#[Autowire('%kernel.project_dir%')]
		private readonly string $projectDir,
		#[Autowire('%kernel.environment%')]
		private readonly string $environment,
		#[Autowire('%iikiti.plugin.store_url%')]
		private readonly string $storeUrl,
		#[Autowire('%iikiti.plugin.allow_non_approved%')]
		private readonly bool $allowNonApproved = false,
		#[Autowire('%iikiti.plugin.allow_third_party_updates%')]
		private readonly bool $allowThirdParty = false,
		#[Autowire('%iikiti.plugin.require_signature%')]
		private readonly bool $requireSignature = true,
		#[Autowire('%iikiti.plugin.store_public_key%')]
		private readonly string $storePublicKey = '',
		#[Autowire('%iikiti.plugin.store_timeout%')]
		private readonly int $timeout = 30,
	) {
	}

	public function getStoreUrl(): string
	{
		return rtrim($this->storeUrl, '/');
	}

	/**
	 * Determine whether a store URL is the configured (trusted) store.
	 */
	public function isTrustedStore(?string $storeUrl): bool
	{
		if (null === $storeUrl) {
			return true;
		}

		return rtrim($storeUrl, '/') === $this->getStoreUrl();
	}

	/**
	 * Resolve the source for a store URL, refusing third-party sources on
	 * production and when not explicitly allowed.
	 *
	 * @throws SecurityViolationException
	 */
	public function resolveSource(?string $storeUrl): PluginSource
	{
		if ($this->isTrustedStore($storeUrl)) {
			return PluginSource::IikitiStore;
		}

		if (!$this->allowThirdParty) {
			throw new SecurityViolationException('Third-party plugin stores are disabled. Set PLUGIN_ALLOW_THIRD_PARTY_UPDATES=true in a non-production environment to enable them.');
		}

		if ($this->validator->isProduction($this->environment)) {
			throw new SecurityViolationException('Third-party plugin stores cannot be used on a production server.');
		}

		return PluginSource::ThirdParty;
	}

	/**
	 * Fetch store metadata for a plugin version.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws PluginNotFoundException
	 */
	public function fetchMetadata(string $slug, string $version, ?string $storeUrl = null): array
	{
		$url = $this->getStoreUrl().self::API_PREFIX.'/'.rawurlencode($slug).'/'.rawurlencode($version).'/download';

		try {
			$response = $this->httpClient->request('GET', $url, [
				'timeout' => $this->timeout,
				'headers' => ['Accept' => 'application/json'],
			]);

			if (404 === $response->getStatusCode()) {
				throw new PluginNotFoundException(sprintf('Plugin "%s" version "%s" was not found in the store.', $slug, $version));
			}

			if ($response->getStatusCode() >= 400) {
				throw new PluginException(sprintf('Store returned HTTP %d for plugin "%s".', $response->getStatusCode(), $slug));
			}

			$data = $response->toArray(false);
		} catch (PluginException $exception) {
			throw $exception;
		} catch (\Throwable $exception) {
			throw new PluginException(sprintf('Could not reach the plugin store: %s', $exception->getMessage()), 0, $exception);
		}

		if (!isset($data['download_url']) || !is_string($data['download_url'])) {
			throw new PluginException('Store response is missing a download_url.');
		}

		return $data;
	}

	/**
	 * Download, verify and return a plugin package ready for installation.
	 *
	 * @throws PluginException on network, integrity, authenticity or state failure
	 */
	public function download(string $slug, string $version, ?string $storeUrl = null): PluginPackage
	{
		$source = $this->resolveSource($storeUrl);
		$metadata = $this->fetchMetadata($slug, $version, $storeUrl);

		$state = PluginState::fromStore(isset($metadata['state']) ? (string) $metadata['state'] : null);
		$this->validator->enforceState($state, $this->allowNonApproved, $this->environment);

		$downloadUrl = (string) $metadata['download_url'];
		$filePath = $this->cachePath($slug, $version);

		$this->downloadFile($downloadUrl, $filePath);

		$checksum = isset($metadata['checksum']) ? (string) $metadata['checksum'] : null;
		$this->verifyChecksum($filePath, $checksum);

		$signature = isset($metadata['signature']) ? (string) $metadata['signature'] : null;
		$this->verifySignature($filePath, $signature);

		return new PluginPackage(
			slug: $slug,
			version: $version,
			filePath: $filePath,
			source: $source,
			state: $state,
			checksum: $checksum,
			signature: $signature,
			signedBy: isset($metadata['signed_by']) ? (string) $metadata['signed_by'] : null,
		);
	}

	/**
	 * Ask the store whether a newer version is available.
	 *
	 * @return array<string,mixed>|null null when already up to date
	 */
	public function checkForUpdate(string $slug, string $currentVersion, ?string $storeUrl = null): ?array
	{
		$this->resolveSource($storeUrl);

		$url = $this->getStoreUrl().self::API_PREFIX.'/'.rawurlencode($slug).'/updates?current='.rawurlencode($currentVersion);

		try {
			$response = $this->httpClient->request('GET', $url, [
				'timeout' => $this->timeout,
				'headers' => ['Accept' => 'application/json'],
			]);

			if (404 === $response->getStatusCode()) {
				return null;
			}

			if ($response->getStatusCode() >= 400) {
				throw new PluginException(sprintf('Store returned HTTP %d while checking for updates to "%s".', $response->getStatusCode(), $slug));
			}

			$data = $response->toArray(false);
		} catch (PluginException $exception) {
			throw $exception;
		} catch (\Throwable $exception) {
			throw new PluginException(sprintf('Could not reach the plugin store: %s', $exception->getMessage()), 0, $exception);
		}

		if (!isset($data['version']) || (string) $data['version'] === $currentVersion) {
			return null;
		}

		return $data;
	}

	private function cachePath(string $slug, string $version): string
	{
		$dir = $this->projectDir.'/'.PluginLoader::CACHE_DIR;
		if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
			throw new PluginException(sprintf('Could not create plugin cache directory "%s".', $dir));
		}

		return $dir.'/'.$slug.'-'.$version.'.zip';
	}

	private function downloadFile(string $url, string $targetPath): void
	{
		try {
			$response = $this->httpClient->request('GET', $url, ['timeout' => $this->timeout]);
			$contents = $response->getContent();
		} catch (\Throwable $exception) {
			throw new PluginException(sprintf('Could not download plugin package: %s', $exception->getMessage()), 0, $exception);
		}

		if (false === file_put_contents($targetPath, $contents)) {
			throw new PluginException(sprintf('Could not write plugin package to "%s".', $targetPath));
		}
	}

	/**
	 * Verify a "sha256:<hex>" or bare hex checksum.
	 *
	 * @throws SecurityViolationException
	 */
	private function verifyChecksum(string $filePath, ?string $checksum): void
	{
		if (null === $checksum || '' === $checksum) {
			return;
		}

		[$algorithm, $expected] = str_contains($checksum, ':') ?
			explode(':', $checksum, 2) :
			['sha256', $checksum];

		if (!in_array($algorithm, hash_algos(), true)) {
			throw new SecurityViolationException(sprintf('Store returned an unsupported checksum algorithm "%s".', $algorithm));
		}

		$actual = hash_file($algorithm, $filePath);
		if (false === $actual || !hash_equals(strtolower($expected), strtolower($actual))) {
			@unlink($filePath);

			throw new SecurityViolationException(sprintf('Checksum mismatch for plugin package. Expected %s, got %s.', $expected, (string) $actual));
		}
	}

	/**
	 * Verify an Ed25519 signature over the raw package bytes.
	 *
	 * @throws SecurityViolationException
	 */
	private function verifySignature(string $filePath, ?string $signature): void
	{
		$hasSignature = null !== $signature && '' !== $signature;

		if (!$hasSignature) {
			if ($this->requireSignature) {
				@unlink($filePath);

				throw new SecurityViolationException('Plugin package is not signed and signatures are required.');
			}

			return;
		}

		if ('' === $this->storePublicKey) {
			@unlink($filePath);

			throw new SecurityViolationException('Plugin package is signed but no store public key is configured (PLUGIN_STORE_PUBLIC_KEY).');
		}

		if (!function_exists('sodium_crypto_sign_verify_detached')) {
			@unlink($filePath);

			throw new SecurityViolationException('The sodium extension is required to verify plugin signatures.');
		}

		$publicKey = base64_decode($this->storePublicKey, true);
		$decodedSignature = base64_decode($signature, true);
		$message = file_get_contents($filePath);

		if (false === $publicKey || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen($publicKey) ||
			false === $decodedSignature || SODIUM_CRYPTO_SIGN_BYTES !== strlen($decodedSignature) ||
			false === $message
		) {
			@unlink($filePath);

			throw new SecurityViolationException('Plugin signature or public key is malformed.');
		}

		if (!sodium_crypto_sign_verify_detached($decodedSignature, $message, $publicKey)) {
			@unlink($filePath);

			throw new SecurityViolationException('Plugin signature verification failed.');
		}
	}
}
