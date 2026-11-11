<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Command\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use iikiti\CMS\Command\Security\RotateApplicationKeysCommand;
use iikiti\CMS\Crypto\TransferCredentialKeyring;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Secrets\SecretsKeyring;
use iikiti\CMS\Secrets\SiteMasterKeyManager;
use iikiti\CMS\Transfer\SiteTransferCredentialVault;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RotateApplicationKeysCommandTest extends TestCase
{
	public function testRotatesStoredCredentialsAndReportsOnlyCounts(): void
	{
		$connection = $this->connection();
		$oldVault = $this->vault($connection, ['old' => base64_encode(str_repeat('o', 32)), 'new' => base64_encode(str_repeat('n', 32))], 'old');
		$oldVault->store(1, 'sensitive-credential');
		$entityManager = $this->entityManager($connection);
		$command = new RotateApplicationKeysCommand(
			$entityManager,
			new SiteMasterKeyManager($entityManager, new SecretsKeyring(['active' => base64_encode(str_repeat('s', 32))], 'active')),
			$this->vault($connection, ['old' => base64_encode(str_repeat('o', 32)), 'new' => base64_encode(str_repeat('n', 32))], 'new'),
		);
		$tester = new CommandTester($command);

		self::assertSame(0, $tester->execute([]));
		self::assertStringContainsString('re-encrypted 1 transfer credential', strtolower($tester->getDisplay()));
		self::assertStringNotContainsString('sensitive-credential', $tester->getDisplay());
		self::assertStringStartsWith('v2.bmV3.v1.', (string) $connection->fetchOne('SELECT encrypted_token FROM site_transfer_credentials WHERE batch_job_id = 1'));
	}

	public function testFailureRollsBackAndDoesNotPrintCredentialData(): void
	{
		$connection = $this->connection();
		$this->vault($connection, ['old' => base64_encode(str_repeat('o', 32))], 'old')->store(2, 'rotatable-credential');
		$this->vault($connection, ['missing' => base64_encode(str_repeat('m', 32))], 'missing')->store(3, 'unavailable-credential');
		$before = $connection->fetchAllKeyValue('SELECT batch_job_id, encrypted_token FROM site_transfer_credentials ORDER BY batch_job_id');
		$entityManager = $this->entityManager($connection);
		$command = new RotateApplicationKeysCommand(
			$entityManager,
			new SiteMasterKeyManager($entityManager, new SecretsKeyring(['active' => base64_encode(str_repeat('s', 32))], 'active')),
			$this->vault($connection, [
				'old' => base64_encode(str_repeat('o', 32)),
				'new' => base64_encode(str_repeat('n', 32)),
			], 'new'),
		);
		$tester = new CommandTester($command);

		self::assertSame(1, $tester->execute([]));
		self::assertStringContainsString('no changes were committed', strtolower($tester->getDisplay()));
		self::assertStringNotContainsString('rotatable-credential', $tester->getDisplay());
		self::assertStringNotContainsString('unavailable-credential', $tester->getDisplay());
		self::assertSame($before, $connection->fetchAllKeyValue('SELECT batch_job_id, encrypted_token FROM site_transfer_credentials ORDER BY batch_job_id'));
	}

	private function connection(): Connection
	{
		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$connection->executeStatement('CREATE TABLE site_transfer_credentials (batch_job_id INTEGER PRIMARY KEY, encrypted_token TEXT NOT NULL, updated_at TEXT NOT NULL)');

		return $connection;
	}

	/** @param array<string,string> $keys */
	private function vault(Connection $connection, array $keys, string $activeKeyId): SiteTransferCredentialVault
	{
		return new SiteTransferCredentialVault(
			$connection,
			new DatabaseTableNameResolver($connection),
			'legacy-app-secret',
			new TransferCredentialKeyring($keys, $activeKeyId),
		);
	}

	private function entityManager(Connection $connection): EntityManagerInterface
	{
		$repository = $this->createStub(EntityRepository::class);
		$repository->method('findAll')->willReturn([]);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('getConnection')->willReturn($connection);
		$entityManager->method('getRepository')->willReturn($repository);

		return $entityManager;
	}
}
