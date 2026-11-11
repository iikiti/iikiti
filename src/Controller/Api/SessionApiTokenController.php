<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api;

use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Security\ApiTokenManager;
use iikiti\CMS\Security\MfaAuthenticationGate;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SessionApiTokenController
{
	public function __construct(
		private Security $security,
		private ApiTokenManager $tokens,
		private MfaAuthenticationGate $mfaGate,
	) {
	}

	#[Route('/api/auth/session-token', name: 'api_auth_session_token', methods: ['POST'])]
	public function issue(): JsonResponse
	{
		$user = $this->security->getUser();
		if (!$user instanceof User || !$this->mfaGate->allowsFullyAuthenticatedUser()) {
			return new JsonResponse(['error' => 'Authenticated user session required.'], Response::HTTP_UNAUTHORIZED);
		}

		$token = $this->tokens->getOrCreateToken($user);

		return new JsonResponse(
			[
				'token' => $token->getToken(),
				'username' => $user->getUsername() ?? '',
				'timeZone' => $user->getTimeZone()?->getId() ?? '',
				'locale' => $user->getPreferredLocale()?->getTag() ?? '',
			],
			Response::HTTP_OK,
			['Cache-Control' => 'private, no-store'],
		);
	}
}
