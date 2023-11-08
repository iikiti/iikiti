<?php
namespace iikiti\CMS\Security\Voter;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @template TAttribute of string
 * @template TSubject of mixed
 * @extends Voter<TAttribute,TSubject>
 */
class MultiFactorVoter extends Voter {

	protected function supports(string $attribute, mixed $subject): bool {
		return $attribute == 'IS_MFA_IN_PROGRESS';
	}

	protected function voteOnAttribute(
		string $attribute,
		mixed $subject,
		TokenInterface $token
	): bool {
		if($token instanceof UsernamePasswordToken) {
			dump(1);
		}
		return false;
	}

}

