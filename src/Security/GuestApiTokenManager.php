<?php

declare(strict_types=1);

namespace iikiti\CMS\Security;

use iikiti\CMS\Crypto\GuestApiTokenKeyring;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Issues short-lived, site-bound credentials for public read API operations. */
final readonly class GuestApiTokenManager
{
	private const TTL_SECONDS = 300;
	private GuestApiTokenKeyring $keyring;

	public function __construct(
		#[Autowire('%kernel.secret%')]
		private string $legacySecret,
		?GuestApiTokenKeyring $keyring = null,
		private bool $allowLegacyTokens = false,
	) {
		$this->keyring = $keyring ?? new GuestApiTokenKeyring(
			['test' => base64_encode(hash('sha256', $legacySecret, true))],
			'test',
		);
	}

	public function issue(string $siteHost): string
	{
		$siteHost = self::normalizeHost($siteHost);
		$payload = json_encode([
			'host' => $siteHost,
			'exp' => time() + self::TTL_SECONDS,
			'nonce' => bin2hex(random_bytes(16)),
		], JSON_THROW_ON_ERROR);
		$keyId = $this->keyring->getActiveKeyId();
		$encodedKeyId = self::base64UrlEncode($keyId);
		$encodedPayload = self::base64UrlEncode($payload);
		$signature = self::base64UrlEncode(hash_hmac('sha256', $this->signingMessage($keyId, $encodedPayload), $this->keyring->getKey($keyId), true));

		return 'guest2.'.$encodedKeyId.'.'.$encodedPayload.'.'.$signature;
	}

	public function decode(string $token): ?GuestApiUser
	{
		$parts = explode('.', $token);
		if (4 === count($parts) && 'guest2' === $parts[0]) {
			[, $encodedKeyId, $encodedPayload, $signature] = $parts;
			$keyId = self::base64UrlDecode($encodedKeyId);
			if ('' === $keyId) {
				return null;
			}
			try {
				$expected = self::base64UrlEncode(hash_hmac(
					'sha256',
					$this->signingMessage($keyId, $encodedPayload),
					$this->keyring->getKey($keyId),
					true,
				));
			} catch (\UnexpectedValueException) {
				return null;
			}
		} elseif (3 === count($parts) && 'guest1' === $parts[0]) {
			if (!$this->allowLegacyTokens) {
				return null;
			}
			[, $encodedPayload, $signature] = $parts;
			$expected = self::base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->legacySecret, true));
		} else {
			return null;
		}
		if (!hash_equals($expected, $signature)) {
			return null;
		}

		$payload = json_decode(self::base64UrlDecode($encodedPayload), true);
		if (!is_array($payload) ||
			!is_string($payload['host'] ?? null) ||
			!is_int($payload['exp'] ?? null) ||
			$payload['exp'] < time() ||
			!is_string($payload['nonce'] ?? null) ||
			1 !== preg_match('/^[a-f0-9]{32}$/D', $payload['nonce'])) {
			return null;
		}

		try {
			$siteHost = self::normalizeHost($payload['host']);
		} catch (\InvalidArgumentException) {
			return null;
		}

		return new GuestApiUser($siteHost, 'guest:'.hash('sha256', $token));
	}

	private static function normalizeHost(string $host): string
	{
		$host = strtolower(trim($host));
		if ('' === $host || strlen($host) > 253 || str_contains($host, ':') || str_contains($host, '/') || str_contains($host, "\r") || str_contains($host, "\n")) {
			throw new \InvalidArgumentException('Invalid guest API site host.');
		}
		if (false === filter_var($host, FILTER_VALIDATE_IP) && 1 !== preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host)) {
			throw new \InvalidArgumentException('Invalid guest API site host.');
		}

		return $host;
	}

	private static function base64UrlEncode(string $value): string
	{
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	private static function base64UrlDecode(string $value): string
	{
		$decoded = base64_decode(strtr($value, '-_', '+/'), true);
		if (false === $decoded) {
			return '';
		}

		return $decoded;
	}

	private function signingMessage(string $keyId, string $encodedPayload): string
	{
		return "iikiti-guest-api-token-v2\0".$keyId."\0".$encodedPayload;
	}
}
