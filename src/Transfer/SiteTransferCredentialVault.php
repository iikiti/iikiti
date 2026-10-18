<?php

declare(strict_types=1);

namespace iikiti\CMS\Transfer;

use Doctrine\DBAL\Connection;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;

final class SiteTransferCredentialVault
{
	private readonly string $key;

	public function __construct(private readonly Connection $connection, private readonly DatabaseTableNameResolver $tableNames, string $applicationSecret)
	{
		if ('' === $applicationSecret) {
			throw new \InvalidArgumentException('APP_SECRET is required to protect transfer credentials.');
		}
		$this->key = hash('sha256', $applicationSecret, true);
	}

	public function store(int|string $jobId, string $token): void
	{
		if ('' === trim($token) || strlen($token) > 4096) {
			throw new \InvalidArgumentException('A valid source API token is required.');
		}
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$ciphertext = sodium_crypto_secretbox($token, $nonce, $this->key);
		$this->connection->executeStatement(
			$this->tableNames->qualifySql('INSERT INTO site_transfer_credentials (batch_job_id, encrypted_token, updated_at) VALUES (:job, :token, :now) ON CONFLICT (batch_job_id) DO UPDATE SET encrypted_token = EXCLUDED.encrypted_token, updated_at = EXCLUDED.updated_at'),
			['job' => $jobId, 'token' => base64_encode($nonce.$ciphertext), 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
		);
	}

	public function get(int|string $jobId): string
	{
		$stored = $this->connection->fetchOne($this->tableNames->qualifySql('SELECT encrypted_token FROM site_transfer_credentials WHERE batch_job_id = :job'), ['job' => $jobId]);
		if (!is_string($stored)) {
			throw new \LogicException('Transfer credentials are not available for this job.');
		}
		$encoded = base64_decode($stored, true);
		if (false === $encoded || strlen($encoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			throw new \UnexpectedValueException('Stored transfer credentials are invalid.');
		}
		$nonce = substr($encoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$plaintext = sodium_crypto_secretbox_open(substr($encoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
		if (false === $plaintext) {
			throw new \UnexpectedValueException('Stored transfer credentials cannot be decrypted.');
		}

		return $plaintext;
	}

	public function delete(int|string $jobId): void
	{
		$this->connection->executeStatement($this->tableNames->qualifySql('DELETE FROM site_transfer_credentials WHERE batch_job_id = :job'), ['job' => $jobId]);
	}
}
