<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Challenge;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encodes and authenticates small JSON payloads as `base64url(json).hmac`.
 *
 * Used for both the proof-of-work challenge and the clearance cookie. Keys are derived
 * per purpose so a challenge token can never be replayed as a clearance cookie.
 */
final readonly class FirewallChallengeToken
{
	private const KEY_CONTEXT = 'iikiti-fw-challenge-v1:';
	private const MAX_TOKEN_BYTES = 2048;

	public function __construct(
		#[Autowire('%kernel.secret%')]
		private string $applicationSecret,
	) {
	}

	/** @param array<string,mixed> $payload */
	public function sign(array $payload, string $purpose): string
	{
		$encoded = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

		return $encoded.'.'.hash_hmac('sha256', $encoded, $this->key($purpose));
	}

	/** @return array<string,mixed>|null the payload when the signature verifies, otherwise null */
	public function verify(string $token, string $purpose): ?array
	{
		if (strlen($token) > self::MAX_TOKEN_BYTES || 1 !== substr_count($token, '.')) {
			return null;
		}

		[$encoded, $signature] = explode('.', $token, 2);
		$expected = hash_hmac('sha256', $encoded, $this->key($purpose));
		if (!hash_equals($expected, $signature)) {
			return null;
		}

		$decoded = self::base64UrlDecode($encoded);
		$payload = null === $decoded ? null : json_decode($decoded, true);

		return is_array($payload) ? $payload : null;
	}

	public static function base64UrlEncode(string $value): string
	{
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	public static function base64UrlDecode(string $value): ?string
	{
		$decoded = base64_decode(strtr($value, '-_', '+/'), true);

		return false === $decoded ? null : $decoded;
	}

	private function key(string $purpose): string
	{
		return hash('sha256', self::KEY_CONTEXT.$purpose.':'.$this->applicationSecret, true);
	}
}
