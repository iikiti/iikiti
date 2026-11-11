<?php

declare(strict_types=1);

namespace iikiti\CMS\Transfer;

use Doctrine\DBAL\Connection;
use iikiti\CMS\Crypto\TransferCredentialKeyring;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;

final class SiteTransferCredentialVault
{
	private readonly ?string $legacyKey;

	public function __construct(
		private readonly Connection $connection,
		private readonly DatabaseTableNameResolver $tableNames,
		string $applicationSecret,
		private readonly TransferCredentialKeyring $keyring,
		private readonly bool $allowLegacyCiphertext = false,
	) {
		if ($allowLegacyCiphertext && '' === $applicationSecret) {
			throw new \InvalidArgumentException('APP_SECRET is required to protect transfer credentials.');
		}
		$this->legacyKey = $allowLegacyCiphertext ? hash('sha256', $applicationSecret, true) : null;
	}

	public function store(int|string $jobId, string $token): void
	{
		if ('' === trim($token) || strlen($token) > 4096) {
			throw new \InvalidArgumentException('A valid source API token is required.');
		}
		$encrypted = $this->encrypt($jobId, $token);
		$this->connection->executeStatement(
			$this->tableNames->qualifySql('INSERT INTO site_transfer_credentials (batch_job_id, encrypted_token, updated_at) VALUES (:job, :token, :now) ON CONFLICT (batch_job_id) DO UPDATE SET encrypted_token = EXCLUDED.encrypted_token, updated_at = EXCLUDED.updated_at'),
			['job' => $jobId, 'token' => $encrypted, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
		);
	}

	public function get(int|string $jobId): string
	{
		$stored = $this->connection->fetchOne($this->tableNames->qualifySql('SELECT encrypted_token FROM site_transfer_credentials WHERE batch_job_id = :job'), ['job' => $jobId]);
		if (!is_string($stored)) {
			throw new \LogicException('Transfer credentials are not available for this job.');
		}

		return $this->decrypt($jobId, $stored);
	}

	/** Re-encrypts legacy and old-key credentials with the active key. */
	public function reencryptAll(): int
	{
		$rows = $this->connection->fetchAllAssociative($this->tableNames->qualifySql('SELECT batch_job_id, encrypted_token FROM site_transfer_credentials'));
		if ([] === $rows) {
			return 0;
		}
		$activeKeyId = $this->keyring->getActiveKeyId();
		$changed = 0;
		foreach ($rows as $row) {
			$jobId = $row['batch_job_id'] ?? null;
			$stored = $row['encrypted_token'] ?? null;
			if ((!is_int($jobId) && !is_string($jobId)) || !is_string($stored)) {
				throw new \UnexpectedValueException('A stored transfer credential row is invalid.');
			}

			$token = $this->decrypt($jobId, $stored);
			try {
				if ($this->activeKeyId($stored) === $activeKeyId) {
					continue;
				}

				$encrypted = $this->encrypt($jobId, $token);
				$verified = $this->decrypt($jobId, $encrypted);
				try {
					if (!hash_equals($token, $verified)) {
						throw new \UnexpectedValueException('A transfer credential could not be verified after re-encryption.');
					}
				} finally {
					sodium_memzero($verified);
				}

				$this->connection->executeStatement(
					$this->tableNames->qualifySql('UPDATE site_transfer_credentials SET encrypted_token = :token, updated_at = :now WHERE batch_job_id = :job'),
					['job' => $jobId, 'token' => $encrypted, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
				);
				++$changed;
			} finally {
				sodium_memzero($token);
			}
		}

		return $changed;
	}

	private function encrypt(int|string $jobId, string $token): string
	{
		$keyId = $this->keyring->getActiveKeyId();

		return 'v2.'.self::base64UrlEncode($keyId).'.'.$this->keyring->wrap($token, $this->associatedData($jobId));
	}

	private function decrypt(int|string $jobId, string $stored): string
	{
		$keyId = $this->activeKeyId($stored);
		if (null !== $keyId) {
			$parts = explode('.', $stored, 3);

			return $this->keyring->unwrap($keyId, $parts[2], $this->associatedData($jobId));
		}
		if (null === $this->legacyKey) {
			throw new \UnexpectedValueException('Legacy transfer credential ciphertext is disabled.');
		}

		$encoded = base64_decode($stored, true);
		if (false === $encoded || strlen($encoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			throw new \UnexpectedValueException('Stored transfer credentials are invalid.');
		}
		$nonce = substr($encoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$plaintext = sodium_crypto_secretbox_open(substr($encoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->legacyKey);
		if (false === $plaintext) {
			throw new \UnexpectedValueException('Stored transfer credentials cannot be decrypted.');
		}

		return $plaintext;
	}

	private function activeKeyId(string $stored): ?string
	{
		if (!str_starts_with($stored, 'v2.')) {
			return null;
		}
		$parts = explode('.', $stored, 3);
		if (3 !== count($parts) || '' === $parts[1] || '' === $parts[2]) {
			throw new \UnexpectedValueException('Stored transfer credentials are invalid.');
		}

		$keyId = self::base64UrlDecode($parts[1]);
		if ('' === $keyId) {
			throw new \UnexpectedValueException('Stored transfer credentials are invalid.');
		}

		return $keyId;
	}

	private function associatedData(int|string $jobId): string
	{
		return "iikiti-transfer-credential-v1\0".(string) $jobId;
	}

	private static function base64UrlEncode(string $value): string
	{
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	private static function base64UrlDecode(string $value): string
	{
		$decoded = base64_decode(strtr($value, '-_', '+/'), true);

		return false === $decoded ? '' : $decoded;
	}

	public function delete(int|string $jobId): void
	{
		$this->connection->executeStatement($this->tableNames->qualifySql('DELETE FROM site_transfer_credentials WHERE batch_job_id = :job'), ['job' => $jobId]);
	}
}
