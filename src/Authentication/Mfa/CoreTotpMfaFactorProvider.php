<?php

declare(strict_types=1);

namespace iikiti\CMS\Authentication\Mfa;

use iikiti\CMS\Authentication\Strategy\TotpTokenStrategy;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Workflow\FormStepInterface;
use iikiti\CMS\Workflow\Step\Mfa\MfaFactorStepAdapter;
use iikiti\CMS\Workflow\Step\Mfa\TotpVerificationStep;

final class CoreTotpMfaFactorProvider implements MfaFactorProviderInterface
{
	public function __construct(private readonly TotpTokenStrategy $strategy)
	{
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
