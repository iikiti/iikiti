<?php

namespace iikiti\CMS\Security\Voter;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Security\Authentication\MultiFactorAuthenticationToken;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @template TAttribute of string
 * @template TSubject of mixed
 *
 * @extends Voter<TAttribute, TSubject>
 */
class MultiFactorVoter extends Voter
{
	public const IS_MFA_IN_PROGRESS = 'IS_MFA_IN_PROGRESS';

	public function __construct(
		private Security $s,
		private EntityManagerInterface $em
	) {
	}

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
		if (
			self::IS_MFA_IN_PROGRESS == $attribute &&
			!($token instanceof MultiFactorAuthenticationToken)
		) {
			return false;
		} elseif ($token instanceof MultiFactorAuthenticationToken) {
			return $token->isAuthenticated();
		}

		return true;
	}

	public function supportsType(string $subjectType): bool
	{
		return Request::class == $subjectType;
	}
}
