<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Object\Site;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generates and verifies short-lived HMAC-SHA256 signed URLs for digital asset delivery.
 */
final readonly class AssetSignedUrlService
{
	public const MIN_TTL_SECONDS = 1;
	public const MAX_TTL_SECONDS = 86400;
	public const DEFAULT_TTL_SECONDS = 900;

	/** @var list<string> */
	public const ALLOWED_DISPOSITIONS = ['inline', 'attachment'];

	private const TOKEN_PATTERN = '/^[a-f0-9]{64}$/';
	private const VARIANT_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';
	private const SIGNATURE_PATTERN = '/^[a-f0-9]{64}$/';

	public function __construct(
		#[Autowire('%asset_signing_secret%')]
		private string $signingSecret = '',
		#[Autowire('%kernel.secret%')]
		private string $applicationSecret = '',
	) {
	}

	/**
	 * @return array{url: string, expiresAt: string, signature: string}
	 */
	public function generate(
		AbstractDigitalAsset $asset,
		Site $site,
		string $variant = 'original',
		int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
		string $disposition = 'inline',
		?int $version = null,
		?int $now = null,
	): array {
		$publicToken = $asset->getPublicToken();
		if (1 !== preg_match(self::TOKEN_PATTERN, $publicToken)) {
			throw new \InvalidArgumentException('Asset public token is invalid.');
		}
		if (1 !== preg_match(self::VARIANT_PATTERN, $variant)) {
			throw new \InvalidArgumentException('Asset variant key is invalid.');
		}
		if ($ttlSeconds < self::MIN_TTL_SECONDS || $ttlSeconds > self::MAX_TTL_SECONDS) {
			throw new \InvalidArgumentException(sprintf('Signed URL TTL must be between %d and %d seconds.', self::MIN_TTL_SECONDS, self::MAX_TTL_SECONDS));
		}
		if (!in_array($disposition, self::ALLOWED_DISPOSITIONS, true)) {
			throw new \InvalidArgumentException('Signed URL disposition must be "inline" or "attachment".');
		}
		if (null !== $version && $version < 1) {
			throw new \InvalidArgumentException('Signed URL version must be a positive integer.');
		}

		$siteId = $site->getId();
		if (null === $siteId || 1 !== preg_match('/^\d+$/', (string) $siteId)) {
			throw new \InvalidArgumentException('Target site must have a persisted numeric ID.');
		}

		$expiresTimestamp = ($now ?? time()) + $ttlSeconds;
		$signature = $this->sign($publicToken, $variant, (string) $siteId, $expiresTimestamp, $disposition, $version);

		$query = [
			'exp' => (string) $expiresTimestamp,
			'site' => (string) $siteId,
			'd' => $disposition,
		];
		if (null !== $version) {
			$query['v'] = (string) $version;
		}
		$query['sig'] = $signature;

		return [
			'url' => '/assets/signed/'.$publicToken.'/'.$variant.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986),
			'expiresAt' => (new \DateTimeImmutable('@'.$expiresTimestamp))->format(\DateTimeInterface::ATOM),
			'signature' => $signature,
		];
	}

	public function verify(
		string $publicToken,
		string $variant,
		int|string $siteId,
		int $expiresAt,
		string $disposition,
		string $signature,
		?int $version = null,
		?int $now = null,
	): bool {
		if (1 !== preg_match(self::TOKEN_PATTERN, $publicToken)) {
			return false;
		}
		if (1 !== preg_match(self::VARIANT_PATTERN, $variant)) {
			return false;
		}
		$normalizedSiteId = (string) $siteId;
		if (1 !== preg_match('/^\d+$/', $normalizedSiteId)) {
			return false;
		}
		if (!in_array($disposition, self::ALLOWED_DISPOSITIONS, true)) {
			return false;
		}
		if (null !== $version && $version < 1) {
			return false;
		}
		if (1 !== preg_match(self::SIGNATURE_PATTERN, $signature)) {
			return false;
		}
		if ($expiresAt < ($now ?? time())) {
			return false;
		}

		$expected = $this->sign($publicToken, $variant, $normalizedSiteId, $expiresAt, $disposition, $version);

		return hash_equals($expected, $signature);
	}

	private function sign(
		string $publicToken,
		string $variant,
		string $siteId,
		int $expiresAt,
		string $disposition,
		?int $version,
	): string {
		$payload = implode("\n", [
			'v1',
			$publicToken,
			$variant,
			$siteId,
			null === $version ? '' : (string) $version,
			(string) $expiresAt,
			$disposition,
		]);

		return hash_hmac('sha256', $payload, $this->signingKey());
	}

	private function signingKey(): string
	{
		$secret = '' !== trim($this->signingSecret) ? $this->signingSecret : $this->applicationSecret;
		if ('' === trim($secret)) {
			throw new \RuntimeException('An asset signing secret or APP_SECRET must be configured.');
		}

		return hash_hmac('sha256', 'iikiti.digital_asset.signed_url.v1', $secret, true);
	}
}
