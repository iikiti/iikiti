<?php

declare(strict_types=1);

namespace iikiti\CMS\Authentication\Mfa;

use iikiti\CMS\Authentication\Mail\MfaCodeMailerInterface;
use iikiti\CMS\Authentication\Strategy\EmailTokenStrategy;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Workflow\FormStepInterface;
use iikiti\CMS\Workflow\Step\Mfa\EmailVerificationStep;
use iikiti\CMS\Workflow\Step\Mfa\MfaFactorStepAdapter;

final class CoreEmailMfaFactorProvider implements MfaFactorProviderInterface
{
	public function __construct(
		private readonly EmailTokenStrategy $strategy,
		private readonly MfaCodeMailerInterface $mailer,
	) {
	}

	public function getKey(): string
	{
		return 'email';
	}

	public function getProofKey(): string
	{
		return 'email_verified';
	}

	public function getVersion(): string
	{
		return 'core-1';
	}

	public function isAvailableFor(User $user): bool
	{
		$emails = $user->getEmails() ?? [];

		return isset($emails[0]) && '' !== $emails[0];
	}

	public function createStep(string $placeId, array $context): ?FormStepInterface
	{
		$email = $context['mfa_email'] ?? null;
		if (!is_string($email) || '' === $email) {
			return null;
		}

		return new MfaFactorStepAdapter($placeId, new EmailVerificationStep($this->strategy, $this->mailer, $email));
	}
}
