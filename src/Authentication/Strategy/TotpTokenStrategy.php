<?php

namespace iikiti\CMS\Authentication\Strategy;

use iikiti\CMS\Authentication\Challenge;
use iikiti\CMS\Authentication\ChallengeInterface;
use OTPHP\OTPInterface;
use OTPHP\TOTP;
use OTPHP\TOTPInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Time-based one-time passwords (RFC 6238).
 */
class TotpTokenStrategy extends AbstractOtpTokenStrategy
{
	/**
	 * @return ChallengeInterface<TOTPInterface>
	 */
	public function generateChallenge(
		#[\SensitiveParameter] string $secret,
	): ChallengeInterface {
		if ('' === $secret) {
			throw new AuthenticationException('Invalid secret.');
		}

		return new Challenge(TOTP::createFromSecret($secret));
	}

	/**
	 * @param ChallengeInterface<mixed> $challenge
	 */
	public function issueChallenge(
		ChallengeInterface $challenge,
	): void {
	}

	/**
	 * @param ChallengeInterface<mixed> $challenge
	 *
	 * @return array<int,\Exception>
	 */
	public function validateChallenge(
		ChallengeInterface $challenge,
		#[\SensitiveParameter] string $userInput,
	): array {
		if ('' === $userInput) {
			return [new AuthenticationException('Invalid challenge or secret.')];
		}

		$otp = $challenge->get();
		if (!$otp instanceof TOTPInterface) {
			return [new AuthenticationException('Invalid challenge.')];
		}

		$errors = [];
		if (false === $otp->verify($userInput)) {
			$errors[] = new AuthenticationException('Challenge is incorrect.');
		}

		return $errors;
	}

	public function generateSecret(): string
	{
		return $this->generateGetOtp()->getSecret();
	}

	public function generateGetOtp(): OTPInterface
	{
		return TOTP::generate();
	}
}
