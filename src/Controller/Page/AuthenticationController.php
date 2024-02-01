<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Class AuthenticationController.
 */
#[AsController]
class AuthenticationController extends AppController
{
	#[Route('/login', name: 'html_login')]
	public function html_login(
		SessionInterface $session,
		AuthenticationUtils $authenticationUtils,
		AuthorizationCheckerInterface $authChecker
	): Response {
		$error = $authenticationUtils->getLastAuthenticationError();

		// last username entered by the user
		$lastUsername = $authenticationUtils->getLastUsername();

		return $this->render(
			'login.twig',
			[
				'doc' => ['title' => 'iikiti login'],
				'last_username' => $lastUsername,
				'error' => $error,
			]
		);
	}

	#[Route('/logout', name: 'html_logout')]
	public function logout(): void
	{
	}
}
