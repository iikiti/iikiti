<?php

declare(strict_types=1);

namespace iikiti\CMS\Authentication\Mfa;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Workflow\FormStepInterface;
use iikiti\CMS\Workflow\Step\Mfa\BackupCodeStep;
use iikiti\CMS\Workflow\Step\Mfa\MfaFactorStepAdapter;

final class CoreBackupCodeMfaFactorProvider implements MfaFactorProviderInterface
{
	public function __construct(private readonly EntityManagerInterface $entityManager)
	{
	}

	public function getKey(): string
	{
		return 'backup_code';
	}

	public function getProofKey(): string
	{
		return 'backup_code_used';
	}

	public function getVersion(): string
	{
		return 'core-1';
	}

	public function isAvailableFor(User $user): bool
	{
		return true;
	}

	public function createStep(string $placeId, array $context): ?FormStepInterface
	{
		$userId = $context['user_id'] ?? null;
		$hashes = $context['mfa_backup_codes'] ?? null;
		if ((!is_int($userId) && !is_string($userId)) || !is_array($hashes)) {
			return null;
		}
		$user = $this->entityManager->find(User::class, $userId);
		$codeHashes = array_values(array_filter($hashes, static fn (mixed $hash): bool => is_string($hash) && '' !== $hash));
		if (!$user instanceof User || [] === $codeHashes) {
			return null;
		}

		return new MfaFactorStepAdapter($placeId, new BackupCodeStep($user, $this->entityManager, $codeHashes));
	}
}
