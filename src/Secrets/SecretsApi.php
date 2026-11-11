<?php

declare(strict_types=1);

namespace iikiti\CMS\Secrets;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\ObjectProperty;

/** Trusted in-process API for storing encrypted JSON values on CMS objects. */
final readonly class SecretsApi
{
	private const ENVELOPE_VERSION = 1;

	public function __construct(
		private SiteMasterKeyManager $masterKeys,
	) {
	}

	public function set(DbObject $object, string $name, mixed $value): void
	{
		$this->validateName($name);
		try {
			$plaintext = json_encode($value, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new \InvalidArgumentException('Secret values must be JSON-encodable.', 0, $exception);
		}

		$associatedData = $this->masterKeys->getAssociatedData($object, $name);
		$masterKey = $this->masterKeys->getOrCreateFor($object);
		$nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
		try {
			$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $associatedData, $nonce, $masterKey);
		} finally {
			sodium_memzero($masterKey);
			sodium_memzero($plaintext);
		}
		$payload = [
			'version' => self::ENVELOPE_VERSION,
			'nonce' => base64_encode($nonce),
			'ciphertext' => base64_encode($ciphertext),
		];
		$property = $object->getProperties()->get($name);
		if (!$property instanceof ObjectProperty) {
			$property = new ObjectProperty();
			$property->setName($name);
			$property->setObject($object);
			$object->getProperties()->set($name, $property);
		}
		$property->writeEncryptedSecretPayload($payload);
	}

	public function get(DbObject $object, string $name): mixed
	{
		$this->validateName($name);
		$property = $object->getProperties()->get($name);
		if (!$property instanceof ObjectProperty || !$property->isSecret()) {
			return null;
		}
		$payload = $property->readEncryptedSecretPayload();
		if (!is_array($payload) || self::ENVELOPE_VERSION !== ($payload['version'] ?? null) || !is_string($payload['nonce'] ?? null) || !is_string($payload['ciphertext'] ?? null)) {
			throw new \UnexpectedValueException('Stored secret payload is invalid.');
		}
		$nonce = base64_decode($payload['nonce'], true);
		$ciphertext = base64_decode($payload['ciphertext'], true);
		if (false === $nonce || SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES !== strlen($nonce) || false === $ciphertext || strlen($ciphertext) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
			throw new \UnexpectedValueException('Stored secret payload is invalid.');
		}
		$masterKey = $this->masterKeys->findFor($object);
		if (null === $masterKey) {
			throw new \UnexpectedValueException('The required secrets master key is unavailable.');
		}
		try {
			$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $this->masterKeys->getAssociatedData($object, $name), $nonce, $masterKey);
			if (false === $plaintext) {
				throw new \UnexpectedValueException('Stored secret payload cannot be authenticated.');
			}
			try {
				return json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
			} catch (\JsonException $exception) {
				throw new \UnexpectedValueException('Stored secret payload is not valid JSON.', 0, $exception);
			} finally {
				sodium_memzero($plaintext);
			}
		} finally {
			sodium_memzero($masterKey);
		}
	}

	public function has(DbObject $object, string $name): bool
	{
		$this->validateName($name);
		$property = $object->getProperties()->get($name);

		return $property instanceof ObjectProperty && $property->isSecret();
	}

	public function delete(DbObject $object, string $name): void
	{
		$this->validateName($name);
		$property = $object->getProperties()->get($name);
		if ($property instanceof ObjectProperty && $property->isSecret()) {
			$object->getProperties()->remove($name);
		}
	}

	private function validateName(string $name): void
	{
		if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $name)) {
			throw new \InvalidArgumentException('Secret property names must contain 1-128 safe ASCII characters.');
		}
	}
}
