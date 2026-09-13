<?php

namespace iikiti\CMS\Workflow\StepProvider;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Authentication\Mail\MfaCodeMailerInterface;
use iikiti\CMS\Authentication\MfaUserConfiguration;
use iikiti\CMS\Authentication\Strategy\EmailTokenStrategy;
use iikiti\CMS\Authentication\Strategy\TotpTokenStrategy;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Workflow\Step\Mfa\BackupCodeStep;
use iikiti\CMS\Workflow\Step\Mfa\EmailVerificationStep;
use iikiti\CMS\Workflow\Step\Mfa\TotpVerificationStep;
use iikiti\CMS\Workflow\StepProviderInterface;
use iikiti\CMS\Workflow\WorkflowInterface;
use iikiti\CMS\Workflow\WorkflowStepInterface;

/**
 * Supplies the multi-factor challenge steps for a user.
 *
 * Steps are built from the workflow context, which is seeded once when the
 * challenge starts and then restored from the session. This keeps the steps
 * stable for the whole challenge and avoids re-querying the user's
 * configuration on every request. The user entity is only loaded when a
 * step needs to persist a change (backup-code consumption).
 */
class MfaStepProvider implements StepProviderInterface
{
	public const WORKFLOW_NAME = 'mfa_authentication';
	public const CONTEXT_METHODS = 'mfa_methods';
	public const CONTEXT_EMAIL = 'mfa_email';
	public const CONTEXT_TOTP_SECRET = 'mfa_totp_secret';
	public const CONTEXT_BACKUP_CODES = 'mfa_backup_codes';

	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly EmailTokenStrategy $emailStrategy,
		private readonly TotpTokenStrategy $totpStrategy,
		private readonly MfaCodeMailerInterface $mailer,
	) {
	}

	public function getWorkflowName(): string
	{
		return self::WORKFLOW_NAME;
	}

	public function supports(mixed $context): bool
	{
		return is_array($context) && is_array($context[self::CONTEXT_METHODS] ?? null);
	}

	/**
	 * @return WorkflowStepInterface[]
	 */
	public function provideSteps(WorkflowInterface $workflow, mixed $context): array
	{
		if (!is_array($context)) {
			return [];
		}

		$methods = $context[self::CONTEXT_METHODS] ?? null;
		if (!is_array($methods)) {
			return [];
		}

		$steps = [];
		foreach ($methods as $method) {
			if (!is_string($method)) {
				continue;
			}

			$step = $this->createStep($method, $context);
			if (null !== $step) {
				$steps[] = $step;
			}
		}

		return $steps;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function createStep(string $method, array $context): ?WorkflowStepInterface
	{
		return match ($method) {
			MfaUserConfiguration::METHOD_EMAIL => $this->createEmailStep($context),
			MfaUserConfiguration::METHOD_TOTP => $this->createTotpStep($context),
			MfaUserConfiguration::METHOD_BACKUP_CODE => $this->createBackupCodeStep($context),
			default => null,
		};
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function createEmailStep(array $context): ?WorkflowStepInterface
	{
		$email = $context[self::CONTEXT_EMAIL] ?? null;
		if (!is_string($email) || '' === $email) {
			return null;
		}

		return new EmailVerificationStep($this->emailStrategy, $this->mailer, $email);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function createTotpStep(array $context): ?WorkflowStepInterface
	{
		$secret = $context[self::CONTEXT_TOTP_SECRET] ?? null;
		if (!is_string($secret) || '' === $secret) {
			return null;
		}

		return new TotpVerificationStep($this->totpStrategy, $secret);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function createBackupCodeStep(array $context): ?WorkflowStepInterface
	{
		$hashes = $context[self::CONTEXT_BACKUP_CODES] ?? null;
		if (!is_array($hashes)) {
			return null;
		}

		$codeHashes = array_values(array_filter(
			$hashes,
			static fn (mixed $hash): bool => is_string($hash) && '' !== $hash
		));
		if ([] === $codeHashes) {
			return null;
		}

		$user = $this->resolveUser($context['user_id'] ?? null);
		if (null === $user) {
			return null;
		}

		return new BackupCodeStep($user, $this->entityManager, $codeHashes);
	}

	private function resolveUser(mixed $userId): ?User
	{
		if (!is_int($userId) && !is_string($userId)) {
			return null;
		}

		$user = $this->entityManager->find(User::class, $userId);

		return $user instanceof User ? $user : null;
	}
}
