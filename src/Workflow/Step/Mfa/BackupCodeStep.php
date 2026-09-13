<?php

namespace iikiti\CMS\Workflow\Step\Mfa;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Authentication\MfaUserConfiguration;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Form\Type\Mfa\BackupCodeFormType;

/**
 * Verifies and consumes a single-use backup code.
 */
class BackupCodeStep extends AbstractMfaStep
{
	/**
	 * @param array<int,string> $codeHashes
	 */
	public function __construct(
		private readonly User $user,
		private readonly EntityManagerInterface $entityManager,
		private readonly array $codeHashes,
	) {
		parent::__construct('mfa_backup_code', 'Backup code', BackupCodeFormType::class, true, true);
	}

	/** Hash matched during validation, reused so the scan runs once. */
	private ?string $matchedHash = null;

	/**
	 * @param array<string,mixed> $context
	 */
	public function validate(array $context): bool
	{
		$code = $this->extractCode($context);
		$this->matchedHash = null !== $code ? $this->findMatchingHash($code) : null;

		return null !== $this->matchedHash;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $context
	 *
	 * @return array<string,mixed>
	 */
	public function process(array $data, array $context): array
	{
		if (null !== $this->matchedHash) {
			$this->consume($this->matchedHash);
			$this->matchedHash = null;
		}

		return ['backup_code_used' => true];
	}

	private function findMatchingHash(string $code): ?string
	{
		foreach ($this->codeHashes as $hash) {
			if (password_verify($code, $hash)) {
				return $hash;
			}
		}

		return null;
	}

	/**
	 * Removes a used backup code so it cannot be replayed.
	 */
	private function consume(string $hash): void
	{
		$preferences = $this->user->getMultifactorPreferences() ?? [];
		$codes = $preferences[MfaUserConfiguration::KEY_BACKUP_CODES] ?? [];

		if (is_array($codes)) {
			$preferences[MfaUserConfiguration::KEY_BACKUP_CODES] = array_values(array_filter(
				$codes,
				static fn (mixed $stored): bool => $stored !== $hash
			));
		}

		$this->user->setMultifactorPreferences($preferences);
		$this->entityManager->flush();
	}
}
