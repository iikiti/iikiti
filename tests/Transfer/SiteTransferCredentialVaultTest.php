<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Transfer;

use Doctrine\DBAL\DriverManager;
use iikiti\CMS\Crypto\TransferCredentialKeyring;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Transfer\SiteTransferCredentialVault;
use PHPUnit\Framework\TestCase;

final class SiteTransferCredentialVaultTest extends TestCase
{
	public function testStoresAndRotatesVersionedCredentialCiphertext(): void
	{
		$connection = $this->connection();
		$oldKeyring = new TransferCredentialKeyring(['old.key' => base64_encode(str_repeat('o', 32))], 'old.key');
		$vault = new SiteTransferCredentialVault($connection, new DatabaseTableNameResolver($connection), 'legacy-app-secret', $oldKeyring);
		$vault->store(12, 'sensitive-api-token');
		$stored = (string) $connection->fetchOne('SELECT encrypted_token FROM site_transfer_credentials WHERE batch_job_id = 12');

		self::assertStringStartsWith('v2.b2xkLmtleQ.v1.', $stored);
		self::assertStringNotContainsString('sensitive-api-token', $stored);
		self::assertSame('sensitive-api-token', $vault->get(12));

		$newKeyring = new TransferCredentialKeyring([
			'old.key' => base64_encode(str_repeat('o', 32)),
			'new.key' => base64_encode(str_repeat('n', 32)),
		], 'new.key');
		$rotatingVault = new SiteTransferCredentialVault($connection, new DatabaseTableNameResolver($connection), 'legacy-app-secret', $newKeyring);

		self::assertSame(1, $rotatingVault->reencryptAll());
		self::assertStringStartsWith('v2.bmV3LmtleQ.v1.', (string) $connection->fetchOne('SELECT encrypted_token FROM site_transfer_credentials WHERE batch_job_id = 12'));
		self::assertSame('sensitive-api-token', $rotatingVault->get(12));
		self::assertSame(0, $rotatingVault->reencryptAll());
	}

	public function testReencryptsLegacySecretboxRows(): void
	{
		$connection = $this->connection();
		$legacySecret = 'legacy-app-secret';
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$key = hash('sha256', $legacySecret, true);
		$legacyCiphertext = base64_encode($nonce.sodium_crypto_secretbox('legacy-token', $nonce, $key));
		$connection->insert('site_transfer_credentials', [
			'batch_job_id' => 24,
			'encrypted_token' => $legacyCiphertext,
			'updated_at' => '2026-11-11 00:00:00',
		]);
		$vault = new SiteTransferCredentialVault(
			$connection,
			new DatabaseTableNameResolver($connection),
			$legacySecret,
			new TransferCredentialKeyring(['active' => base64_encode(str_repeat('a', 32))], 'active'),
			true,
		);

		self::assertSame(1, $vault->reencryptAll());
		self::assertSame('legacy-token', $vault->get(24));
		self::assertStringStartsWith('v2.YWN0aXZl.v1.', (string) $connection->fetchOne('SELECT encrypted_token FROM site_transfer_credentials WHERE batch_job_id = 24'));
	}

	public function testLegacyCiphertextIsRejectedAfterTheMigrationWindowCloses(): void
	{
		$connection = $this->connection();
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$key = hash('sha256', 'legacy-app-secret', true);
		$connection->insert('site_transfer_credentials', [
			'batch_job_id' => 25,
			'encrypted_token' => base64_encode($nonce.sodium_crypto_secretbox('legacy-token', $nonce, $key)),
			'updated_at' => '2026-11-11 00:00:00',
		]);
		$vault = new SiteTransferCredentialVault(
			$connection,
			new DatabaseTableNameResolver($connection),
			'legacy-app-secret',
			new TransferCredentialKeyring(['active' => base64_encode(str_repeat('a', 32))], 'active'),
		);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Legacy transfer credential ciphertext is disabled.');
		$vault->get(25);
	}

	public function testRotationNeedsNoKeyringWhenNoCredentialsExist(): void
	{
		$connection = $this->connection();
		$vault = new SiteTransferCredentialVault(
			$connection,
			new DatabaseTableNameResolver($connection),
			'legacy-app-secret',
			new TransferCredentialKeyring([], ''),
		);

		self::assertSame(0, $vault->reencryptAll());
	}

	public function testUnavailableOldKeyDoesNotOverwriteCiphertext(): void
	{
		$connection = $this->connection();
		$writer = new SiteTransferCredentialVault(
			$connection,
			new DatabaseTableNameResolver($connection),
			'legacy-app-secret',
			new TransferCredentialKeyring(['old' => base64_encode(str_repeat('o', 32))], 'old'),
		);
		$writer->store(30, 'token');
		$before = (string) $connection->fetchOne('SELECT encrypted_token FROM site_transfer_credentials WHERE batch_job_id = 30');
		$reader = new SiteTransferCredentialVault(
			$connection,
			new DatabaseTableNameResolver($connection),
			'legacy-app-secret',
			new TransferCredentialKeyring(['new' => base64_encode(str_repeat('n', 32))], 'new'),
		);

		try {
			$reader->reencryptAll();
			self::fail('Rotation must fail if the key required by a stored row is missing.');
		} catch (\UnexpectedValueException) {
		}

		self::assertSame($before, $connection->fetchOne('SELECT encrypted_token FROM site_transfer_credentials WHERE batch_job_id = 30'));
	}

	private function connection(): \Doctrine\DBAL\Connection
	{
		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$connection->executeStatement('CREATE TABLE site_transfer_credentials (batch_job_id INTEGER PRIMARY KEY, encrypted_token TEXT NOT NULL, updated_at TEXT NOT NULL)');

		return $connection;
	}
}
