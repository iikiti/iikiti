<?php

declare(strict_types=1);

namespace iikiti\CMS\Security;

use iikiti\CMS\Authentication\Token\AuthenticationToken;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** Keeps user API credentials unavailable until an MFA proxy token is completed. */
final readonly class MfaAuthenticationGate
{
	public function __construct(
		private Security $security,
		private TokenStorageInterface $tokenStorage,
	) {
	}

	public function allowsFullyAuthenticatedUser(): bool
	{
		$token = $this->tokenStorage->getToken();
		if ($token instanceof AuthenticationToken && !$token->isAuthenticated()) {
			return false;
		}

		return $this->security->isGranted('IS_AUTHENTICATED_FULLY');
	}
}
