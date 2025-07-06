<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Api\AuthenticationApi;
use iikiti\CMS\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Authentication controller.
 */
#[AsController]
class AuthenticationController extends AppController
{
	#[Route('/login', name: 'html_login')]
	public function html_login(
		SessionInterface $session,
		AuthenticationApi $authApi,
		AuthorizationCheckerInterface $authChecker
	): Response {
		$authInfo = $authApi->getAuthenticationInfo();

		return $this->render(
			'login.twig',
			[
				'doc' => ['title' => 'iikiti login'],
				'last_username' => $authInfo->username,
				'error' => $authInfo->error,
			]
		);
	}

	#[Route('/logout', name: 'html_logout')]
	public function logout(): void
	{
		// This method is intentionally empty as Symfony's security system
		// intercepts the request before it reaches this method
	}
}
