<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Api\AuthenticationApi;
use iikiti\CMS\Controller\AppController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Authentication controller.
 */
#[AsController]
class AuthenticationController extends AppController
{
	#[Route('/login', name: 'html_login', methods: ['GET'])]
	public function html_login(
		Request $request,
		SessionInterface $session,
		AuthenticationApi $authApi,
	): Response {
		$authInfo = $authApi->getAuthenticationInfo();
		$siteHost = $request->query->get('siteHost');
		if (!is_string($siteHost) || '' === trim($siteHost)) {
			$siteHost = $session->get('auth_site_host', $request->getHost());
		}
		$siteHost = strtolower((string) $siteHost);
		$session->set('auth_site_host', $siteHost);

		return $this->render(
			'login.twig',
			[
				'doc' => ['title' => 'iikiti login'],
				'last_username' => $authInfo->username,
				'error' => $authInfo->error,
				'site_host' => $siteHost,
				'site_scheme' => $request->getScheme(),
			]
		);
	}

	#[Route('/api/auth/login', name: 'api_auth_login', methods: ['POST'])]
	public function api_login_fallback(): Response
	{
		// Stateful form_login intercepts this API endpoint before controller dispatch.
		throw new UnauthorizedHttpException('FormLogin', 'The API login authenticator did not run.');
	}

	#[Route('/api/auth/logout', name: 'html_logout', methods: ['GET'])]
	public function logout(): void
	{
		// This method is intentionally empty as Symfony's security system
		// intercepts the request before it reaches this method
	}
}
