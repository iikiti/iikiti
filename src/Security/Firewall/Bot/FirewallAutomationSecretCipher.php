<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Bot;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Protects automation secrets at rest.
 *
 * HMAC secrets are recoverable (needed to verify signatures) so they are encrypted with
 * libsodium secretbox; header secrets only need comparison so they are stored as SHA-256.
 * Both keys derive from the application secret, so a rotated kernel secret invalidates
 * stored secrets and they must be re-entered.
 */
final readonly class FirewallAutomationSecretCipher
{
	private const KEY_CONTEXT = 'iikiti-fw-automation-v1:';
	private const NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

	public function __construct(
		#[Autowire('%kernel.secret%')]
		private string $applicationSecret,
	) {
	}

	/** Returns a SHA-256 digest for header-secret comparison. */
	public function hashHeaderSecret(string $secret): string
	{
		return hash('sha256', self::KEY_CONTEXT.'header:'.$secret);
	}

	/** Encrypts an HMAC secret; output is base64 of nonce || ciphertext. */
	public function encryptHmacSecret(string $secret): string
	{
		$nonce = random_bytes(self::NONCE_BYTES);
		$cipher = sodium_crypto_secretbox($secret, $nonce, $this->key());

		return base64_encode($nonce.$cipher);
	}

	/** Decrypts a value produced by {@see encryptHmacSecret()}, or null when it cannot be opened. */
	public function decryptHmacSecret(string $encoded): ?string
	{
		$raw = base64_decode($encoded, true);
		if (false === $raw || strlen($raw) <= self::NONCE_BYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
			return null;
		}

		$nonce = substr($raw, 0, self::NONCE_BYTES);
		$cipher = substr($raw, self::NONCE_BYTES);
		$plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());

		return false === $plain ? null : $plain;
	}

	private function key(): string
	{
		return hash('sha256', self::KEY_CONTEXT.$this->applicationSecret, true);
	}
}
