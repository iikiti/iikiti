<?php

namespace iikiti\CMS\Security\Voter;

use iikiti\CMS\Security\Authentication\MultiFactorAuthenticationToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @template TAttribute of string
 * @template TSubject of mixed
 *
 * @extends Voter<TAttribute,TSubject>
 */
class MultiFactorVoter extends Voter
{
	public const IS_MFA_IN_PROGRESS = 'IS_MFA_IN_PROGRESS';

	protected function supports(string $attribute, mixed $subject): bool
	{
		return \in_array($attribute, [
			AuthenticatedVoter::IS_AUTHENTICATED_FULLY,
			AuthenticatedVoter::IS_AUTHENTICATED_REMEMBERED,
			AuthenticatedVoter::IS_AUTHENTICATED,
			self::IS_MFA_IN_PROGRESS,
		], true);
	}

	protected function voteOnAttribute(
		string $attribute,
		mixed $subject,
		TokenInterface $token
	): bool {
		$isMfaToken = $token instanceof MultiFactorAuthenticationToken;
		if (self::IS_MFA_IN_PROGRESS == $attribute && !$isMfaToken) {
			return false;
		} elseif ($isMfaToken) {
			/* @var MultiFactorAuthenticationToken $token */
			return $token->isAuthenticated();
		}

		return true;
	}
}
