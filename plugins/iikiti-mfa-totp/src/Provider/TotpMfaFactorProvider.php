<?php

declare(strict_types=1);

namespace iikiti\Extension\MfaTotp\Provider;

use iikiti\CMS\Authentication\Mfa\MfaFactorProviderInterface;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Workflow\FormStepInterface;
use iikiti\CMS\Workflow\Step\Mfa\MfaFactorStepAdapter;
use iikiti\Extension\MfaTotp\Step\TotpVerificationStep;
use iikiti\Extension\MfaTotp\Strategy\TotpTokenStrategy;

final class TotpMfaFactorProvider implements MfaFactorProviderInterface
{
	public function __construct(
		private readonly TotpTokenStrategy $strategy,
		private readonly ?PluginActivationChecker $activationChecker = null,
	) {
	}

	public function getKey(): string
	{
		return 'totp';
	}

	public function getProofKey(): string
	{
		return 'totp_verified';
	}

	public function getVersion(): string
	{
		return 'core-1';
	}

	public function isAvailableFor(User $user): bool
	{
		if (null !== $this->activationChecker && !$this->activationChecker->isActive('iikiti-mfa-totp')) {
			return false;
		}

		return true;
	}

	public function createStep(string $placeId, array $context): ?FormStepInterface
	{
		$secret = $context['mfa_totp_secret'] ?? null;
		if (!is_string($secret) || '' === $secret) {
			return null;
		}

		return new MfaFactorStepAdapter($placeId, new TotpVerificationStep($this->strategy, $secret));
	}
}
