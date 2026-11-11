<?php

declare(strict_types=1);

namespace iikiti\CMS\Secrets;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\SecretSiteKey;

final readonly class SiteMasterKeyManager
{
	public function __construct(
		private EntityManagerInterface $entityManager,
		private SecretsKeyring $keyring,
	) {
	}

	public function getOrCreateFor(DbObject $object): string
	{
		[$scopeKey, $site] = $this->scope($object);
		$key = $this->entityManager->find(SecretSiteKey::class, $scopeKey);
		if (!$key instanceof SecretSiteKey) {
			$keyBytes = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
			try {
				[$wrappedKey, $keyId] = $this->wrap($keyBytes, $scopeKey);
				$key = new SecretSiteKey($scopeKey, $site, $wrappedKey, $keyId);
				$this->entityManager->persist($key);
			} catch (\Throwable $exception) {
				sodium_memzero($keyBytes);
				throw $exception;
			}

			return $keyBytes;
		}

		return $this->unwrap($key);
	}

	public function findFor(DbObject $object): ?string
	{
		[$scopeKey] = $this->scope($object);
		$key = $this->entityManager->find(SecretSiteKey::class, $scopeKey);

		return $key instanceof SecretSiteKey ? $this->unwrap($key) : null;
	}

	public function getAssociatedData(DbObject $object, string $propertyName): string
	{
		[$scopeKey] = $this->scope($object);
		$objectId = $object->getId();
		if (null === $objectId) {
			throw new \InvalidArgumentException('Secret properties require a persisted owner object.');
		}

		return implode("\0", ['iikiti-secret-v1', $scopeKey, (string) $objectId, $propertyName]);
	}

	/** Re-wrap all site master keys with the active key without changing payloads. */
	public function rewrapAll(): int
	{
		$activeKeyId = $this->keyring->getActiveKeyId();
		$changed = 0;
		foreach ($this->entityManager->getRepository(SecretSiteKey::class)->findAll() as $siteKey) {
			$keyBytes = $this->unwrap($siteKey);
			if ($activeKeyId === $siteKey->getWrappingKeyId()) {
				sodium_memzero($keyBytes);
				continue;
			}
			try {
				[$wrappedKey, $newKeyId] = $this->wrap($keyBytes, $siteKey->getScopeKey());
				$verifiedKey = $this->keyring->unwrap($newKeyId, $wrappedKey, $this->keyAssociatedData($siteKey->getScopeKey()));
				if (!hash_equals($keyBytes, $verifiedKey)) {
					throw new \UnexpectedValueException('A secrets master key could not be verified after re-wrapping.');
				}
				sodium_memzero($verifiedKey);
				$siteKey->rewrap($wrappedKey, $newKeyId);
				++$changed;
			} finally {
				sodium_memzero($keyBytes);
			}
		}

		return $changed;
	}

	private function unwrap(SecretSiteKey $key): string
	{
		$keyBytes = $this->keyring->unwrap(
			$key->getWrappingKeyId(),
			$key->getWrappedMasterKey(),
			$this->keyAssociatedData($key->getScopeKey()),
		);
		if (SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== strlen($keyBytes)) {
			throw new \UnexpectedValueException('Stored secrets master key has an invalid length.');
		}

		return $keyBytes;
	}

	/** @return array{string,?Site} */
	private function scope(DbObject $object): array
	{
		if ($object instanceof Site) {
			$siteId = $object->getId();
			if (null === $siteId) {
				throw new \InvalidArgumentException('Secrets on a Site require a persisted Site object.');
			}
		} else {
			$siteId = $object->getSiteId();
		}

		$siteId = (string) $siteId;
		if (!ctype_digit($siteId)) {
			throw new \InvalidArgumentException('Secret owner has an invalid site scope.');
		}
		$normalizedSiteId = ltrim($siteId, '0');
		$normalizedSiteId = '' === $normalizedSiteId ? '0' : $normalizedSiteId;
		if ('0' === $normalizedSiteId) {
			return ['global', null];
		}

		$site = $this->entityManager->getReference(Site::class, $normalizedSiteId);

		return ['site:'.$normalizedSiteId, $site];
	}

	/** @return array{string,string} wrapped key, key ID */
	private function wrap(string $keyBytes, string $scopeKey): array
	{
		$keyId = $this->keyring->getActiveKeyId();

		return [$this->keyring->wrap($keyBytes, $this->keyAssociatedData($scopeKey)), $keyId];
	}

	private function keyAssociatedData(string $scopeKey): string
	{
		return 'iikiti-site-master-v1'."\0".$scopeKey;
	}
}
