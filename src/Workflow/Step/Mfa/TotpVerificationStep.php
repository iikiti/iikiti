<?php

namespace iikiti\CMS\Workflow\Step\Mfa;

use iikiti\CMS\Authentication\Strategy\TotpTokenStrategy;
use iikiti\CMS\Form\Type\Mfa\TotpVerificationFormType;

/**
 * Verifies a time-based one-time password from an authenticator application.
 */
class TotpVerificationStep extends AbstractMfaStep
{
	public function __construct(
		private readonly TotpTokenStrategy $strategy,
		private readonly string $secret,
	) {
		parent::__construct('mfa_totp', 'Authenticator verification', TotpVerificationFormType::class, true, false);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function validate(array $context): bool
	{
		$code = $this->extractCode($context);
		if (null === $code) {
			return false;
		}

		$challenge = $this->strategy->generateChallenge($this->secret);

		return [] === $this->strategy->validateChallenge($challenge, $code);
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $context
	 *
	 * @return array<string,mixed>
	 */
	public function process(array $data, array $context): array
	{
		return ['totp_verified' => true];
	}
}
