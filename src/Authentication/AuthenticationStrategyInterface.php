<?php

namespace iikiti\CMS\Authentication;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A multi-factor authentication method.
 *
 * Implementations generate a challenge for a user, deliver it, and validate
 * the value the user returns. Strategies are tagged so the application can
 * discover every available method.
 */
#[AutoconfigureTag('mfa.auth.strategy')]
interface AuthenticationStrategyInterface
{
	/**
	 * Create the challenge that must be solved for this authentication method.
	 *
	 * @return ChallengeInterface<mixed>
	 */
	public function generateChallenge(
		#[\SensitiveParameter] string $secret,
	): ChallengeInterface;

	/**
	 * Deliver the challenge to the user (for example by email).
	 *
	 * @param ChallengeInterface<mixed> $challenge
	 */
	public function issueChallenge(
		ChallengeInterface $challenge,
	): void;

	/**
	 * Validate the value submitted by the user.
	 *
	 * @param ChallengeInterface<mixed> $challenge
	 *
	 * @return array<int,\Exception> An empty array when the challenge is valid
	 */
	public function validateChallenge(
		ChallengeInterface $challenge,
		#[\SensitiveParameter] string $userInput,
	): array;

	/**
	 * Generate a new secret for this method, or null when none is required.
	 */
	public function generateSecret(): ?string;
}
