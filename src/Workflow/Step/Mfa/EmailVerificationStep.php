<?php

namespace iikiti\CMS\Workflow\Step\Mfa;

use iikiti\CMS\Authentication\Challenge;
use iikiti\CMS\Authentication\Mail\MfaCodeMailerInterface;
use iikiti\CMS\Authentication\Strategy\EmailTokenStrategy;
use iikiti\CMS\Form\Type\Mfa\EmailVerificationFormType;

/**
 * Verifies a numeric code sent to the user by e-mail.
 *
 * The challenge is issued once per step: on later requests the stored hash is
 * reused and validated against the submitted code.
 */
class EmailVerificationStep extends AbstractMfaStep
{
	public const CHALLENGE_KEY = 'mfa_email_challenge';
	public const ISSUED_AT_KEY = 'mfa_email_issued_at';

	private const CHALLENGE_TTL = 300;

	public function __construct(
		private readonly EmailTokenStrategy $strategy,
		private readonly MfaCodeMailerInterface $mailer,
		private readonly string $email,
	) {
		parent::__construct('mfa_email', 'Email verification', EmailVerificationFormType::class, true, false);
	}

	/**
	 * @param array<string,mixed> $context
	 *
	 * @return array<string,mixed>
	 */
	public function getFormOptions(array $context): array
	{
		return ['email' => $this->email];
	}

	/**
	 * @param array<string,mixed> $context
	 *
	 * @return array<string,mixed>
	 */
	public function prepare(array $context): array
	{
		// A challenge that was already issued and is still valid is reused;
		// an expired one is replaced so the user is not stuck on a dead code.
		if (isset($context[self::CHALLENGE_KEY]) && $this->isWithinLifetime($context)) {
			return [];
		}

		$secret = $this->strategy->generateSecret();
		$challenge = $this->strategy->generateChallenge($secret);
		$this->mailer->sendCode($this->email, $secret);

		return [
			self::CHALLENGE_KEY => $challenge->get(),
			self::ISSUED_AT_KEY => time(),
		];
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function validate(array $context): bool
	{
		$hash = $context[self::CHALLENGE_KEY] ?? null;
		$code = $this->extractCode($context);

		if (null === $code || !is_string($hash) || '' === $hash) {
			return false;
		}

		if (false === $this->isWithinLifetime($context)) {
			return false;
		}

		return [] === $this->strategy->validateChallenge(new Challenge($hash), $code);
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $context
	 *
	 * @return array<string,mixed>
	 */
	public function process(array $data, array $context): array
	{
		return ['email_verified' => true];
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function isWithinLifetime(array $context): bool
	{
		$issuedAt = $context[self::ISSUED_AT_KEY] ?? null;
		if (!is_int($issuedAt)) {
			return false;
		}

		return (time() - $issuedAt) <= self::CHALLENGE_TTL;
	}
}
