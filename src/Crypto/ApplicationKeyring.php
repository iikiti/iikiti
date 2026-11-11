<?php

declare(strict_types=1);

namespace iikiti\CMS\Crypto;

abstract readonly class ApplicationKeyring
{
	/** @var array<string,string> decoded key ID => key bytes */
	private array $keys;

	/** @param array<mixed,mixed> $encodedKeys key ID => base64-encoded 32-byte key */
	public function __construct(array $encodedKeys, private string $activeKeyId)
	{
		$keys = [];
		foreach ($encodedKeys as $keyId => $encodedKey) {
			if (!is_string($keyId) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $keyId) || !is_string($encodedKey)) {
				throw new \InvalidArgumentException('The application keyring configuration is invalid.');
			}
			if (44 !== strlen($encodedKey)) {
				throw new \InvalidArgumentException(sprintf('Application key "%s" must be base64-encoded 32-byte key material.', $keyId));
			}
			$key = base64_decode($encodedKey, true);
			if (false === $key || SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== strlen($key)) {
				throw new \InvalidArgumentException(sprintf('Application key "%s" must be base64-encoded 32-byte key material.', $keyId));
			}
			$keys[$keyId] = $key;
		}

		if ([] !== $keys && '' === $activeKeyId) {
			throw new \InvalidArgumentException('An active application key ID is required when key material is configured.');
		}
		if ('' !== $activeKeyId && !isset($keys[$activeKeyId])) {
			throw new \InvalidArgumentException('The active application key ID is not present in the keyring.');
		}

		$this->keys = $keys;
	}

	public function getActiveKeyId(): string
	{
		if ('' === $this->activeKeyId) {
			throw new \LogicException('Application keys are not configured.');
		}

		return $this->activeKeyId;
	}

	public function getActiveKey(): string
	{
		return $this->getKey($this->getActiveKeyId());
	}

	public function getKey(string $keyId): string
	{
		return $this->keys[$keyId] ?? throw new \UnexpectedValueException('The required application key is unavailable.');
	}

	public function wrap(string $plaintext, string $associatedData): string
	{
		$key = $this->getActiveKey();
		$nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
		$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $associatedData, $nonce, $key);

		return 'v1.'.base64_encode($nonce.$ciphertext);
	}

	public function unwrap(string $keyId, string $wrapped, string $associatedData): string
	{
		$key = $this->getKey($keyId);
		if (!str_starts_with($wrapped, 'v1.')) {
			throw new \UnexpectedValueException('Stored encrypted data uses an unsupported version.');
		}
		$decoded = base64_decode(substr($wrapped, 3), true);
		$nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		$minimumLength = $nonceLength + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
		if (false === $decoded || strlen($decoded) < $minimumLength) {
			throw new \UnexpectedValueException('Stored encrypted data is invalid.');
		}
		$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
			substr($decoded, $nonceLength),
			$associatedData,
			substr($decoded, 0, $nonceLength),
			$key,
		);
		if (false === $plaintext) {
			throw new \UnexpectedValueException('Stored encrypted data cannot be authenticated.');
		}

		return $plaintext;
	}
}
