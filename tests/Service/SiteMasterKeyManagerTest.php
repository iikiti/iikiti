<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use iikiti\CMS\Entity\SecretSiteKey;
use iikiti\CMS\Secrets\SecretsKeyring;
use iikiti\CMS\Secrets\SiteMasterKeyManager;
use PHPUnit\Framework\TestCase;

final class SiteMasterKeyManagerTest extends TestCase
{
	public function testRotationRewrapsMasterKeyWithoutChangingIt(): void
	{
		$oldKey = str_repeat('o', 32);
		$newKey = str_repeat('n', 32);
		$oldKeyring = new SecretsKeyring(['old' => base64_encode($oldKey)], 'old');
		$masterKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
		$oldWrapped = $oldKeyring->wrap($masterKey, "iikiti-site-master-v1\0global");
		$siteKey = new SecretSiteKey('global', null, $oldWrapped, 'old');
		$repository = $this->createStub(EntityRepository::class);
		$repository->method('findAll')->willReturn([$siteKey]);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('getRepository')->willReturn($repository);
		$keyring = new SecretsKeyring(['old' => base64_encode($oldKey), 'new' => base64_encode($newKey)], 'new');
		$manager = new SiteMasterKeyManager($entityManager, $keyring);

		self::assertSame(1, $manager->rewrapAll());
		self::assertSame('new', $siteKey->getWrappingKeyId());
		self::assertSame($masterKey, $keyring->unwrap('new', $siteKey->getWrappedMasterKey(), "iikiti-site-master-v1\0global"));
	}

	public function testRotationLeavesRowsAlreadyUsingActiveKeyUntouched(): void
	{
		$keyBytes = str_repeat('a', 32);
		$keyring = new SecretsKeyring(['active' => base64_encode($keyBytes)], 'active');
		$masterKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
		$wrapped = $keyring->wrap($masterKey, "iikiti-site-master-v1\0global");
		$siteKey = new SecretSiteKey('global', null, $wrapped, 'active');
		$repository = $this->createStub(EntityRepository::class);
		$repository->method('findAll')->willReturn([$siteKey]);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('getRepository')->willReturn($repository);

		self::assertSame(0, (new SiteMasterKeyManager($entityManager, $keyring))->rewrapAll());
		self::assertSame($wrapped, $siteKey->getWrappedMasterKey());
	}

	public function testRotationNeedsNoKeyringWhenNoSiteMasterKeysExist(): void
	{
		$repository = $this->createStub(EntityRepository::class);
		$repository->method('findAll')->willReturn([]);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('getRepository')->willReturn($repository);

		self::assertSame(0, (new SiteMasterKeyManager($entityManager, new SecretsKeyring([], '')))->rewrapAll());
	}
}
