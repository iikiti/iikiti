<?php

namespace iikiti\CMS\Authentication\Strategy;

use iikiti\CMS\Authentication\Challenge;
use iikiti\CMS\Authentication\ChallengeInterface;
use iikiti\CMS\Authentication\Exception\AccessDeniedException;
use iikiti\CMS\Authentication\TokenGenerator\StringTokenGenerator;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Delivers a numeric code to the user by e-mail.
 *
 * The plain code is given to the caller so it can be sent, while the challenge
 * stores only a one-way hash of it. Validation therefore relies on the hashed
 * challenge rather than keeping the code itself in the session.
 */
class EmailTokenStrategy extends AbstractTokenStrategy
{
	public const CODE_LENGTH = 6;

	public function __construct(
		private readonly StringTokenGenerator $stringGenerator = new StringTokenGenerator(),
	) {
	}

	/**
	 * @return ChallengeInterface<string>
	 */
	public function generateChallenge(
		#[\SensitiveParameter] string $secret,
	): ChallengeInterface {
		if ('' === $secret) {
			throw new AuthenticationException('Invalid secret.');
		}

		return new Challenge(password_hash($secret, PASSWORD_DEFAULT));
	}

	/**
	 * Delivery is performed by the caller, which still holds the plain code.
	 *
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
		$stored = $challenge->get();
		if ('' === $userInput || !is_string($stored) || '' === $stored) {
			return [new AuthenticationException('Invalid challenge or secret.')];
		}

		$errors = [];
		if ($this->stringGenerator->validate($stored, $userInput)->count() > 0) {
			$errors[] = new AccessDeniedException('Challenge validation failed.');
		}

		return $errors;
	}

	public function generateSecret(): string
	{
		return (string) $this->stringGenerator->generate([
			'min' => 0,
			'max' => 9,
			'length' => self::CODE_LENGTH,
		]);
	}
}
