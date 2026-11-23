<?php

declare(strict_types=1);

namespace iikiti\Extension\MfaEmail\Provider;

use iikiti\CMS\Authentication\Mail\MfaCodeMailerInterface;
use iikiti\CMS\Authentication\Mfa\MfaFactorProviderInterface;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Workflow\FormStepInterface;
use iikiti\CMS\Workflow\Step\Mfa\MfaFactorStepAdapter;
use iikiti\Extension\MfaEmail\Step\EmailVerificationStep;
use iikiti\Extension\MfaEmail\Strategy\EmailTokenStrategy;

final class EmailMfaFactorProvider implements MfaFactorProviderInterface
{
	public function __construct(
		private readonly EmailTokenStrategy $strategy,
		private readonly MfaCodeMailerInterface $mailer,
		private readonly ?PluginActivationChecker $activationChecker = null,
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
		if (null !== $this->activationChecker && !$this->activationChecker->isActive('iikiti-mfa-email')) {
			return false;
		}
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
