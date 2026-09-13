<?php

namespace iikiti\CMS\Security\Voter;

use iikiti\CMS\Authentication\Token\AuthenticationToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Denies access while a multi-factor challenge is outstanding.
 *
 * The {@see AuthenticationToken} reports itself as unauthenticated until the
 * challenge is completed, so this voter ensures attributes such as
 * IS_AUTHENTICATED_FULLY are not granted prematurely.
 *
 * @template TAttribute of string
 * @template TSubject of mixed
 *
 * @extends Voter<TAttribute, TSubject>
 */
class MFAVoter extends Voter
{
	public const IS_MFA_IN_PROGRESS = 'IS_MFA_IN_PROGRESS';

	private const SUPPORTED_ATTRIBUTES = [
		AuthenticatedVoter::IS_AUTHENTICATED_FULLY,
		AuthenticatedVoter::IS_AUTHENTICATED_REMEMBERED,
		AuthenticatedVoter::IS_AUTHENTICATED,
		self::IS_MFA_IN_PROGRESS,
	];

	protected function supports(string $attribute, mixed $subject): bool
	{
		return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true);
	}

	protected function voteOnAttribute(
		string $attribute,
		mixed $subject,
		TokenInterface $token,
		?Vote $vote = null,
	): bool {
		if (self::IS_MFA_IN_PROGRESS === $attribute) {
			return $token instanceof AuthenticationToken;
		}

		if ($token instanceof AuthenticationToken) {
			return $token->isAuthenticated();
		}

		return true;
	}
}
