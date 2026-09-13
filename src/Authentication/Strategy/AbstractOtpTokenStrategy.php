<?php

namespace iikiti\CMS\Authentication\Strategy;

use iikiti\CMS\Authentication\ChallengeInterface;
use OTPHP\OTPInterface;

/**
 * Base class for one-time-password strategies.
 */
abstract class AbstractOtpTokenStrategy extends AbstractTokenStrategy
{
	/**
	 * @param ChallengeInterface<mixed> $challenge
	 */
	abstract public function issueChallenge(
		ChallengeInterface $challenge,
	): void;

	/**
	 * @param ChallengeInterface<mixed> $challenge
	 *
	 * @return array<int,\Exception>
	 */
	abstract public function validateChallenge(
		ChallengeInterface $challenge,
		#[\SensitiveParameter] string $userInput,
	): array;

	abstract public function generateGetOtp(): OTPInterface;
}
