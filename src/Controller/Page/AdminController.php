<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Security\ApiTokenManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration controller.
 *
 * Serves the Svelte 5 admin SPA for all /admin/* routes. Creates an ephemeral
 * API token for the authenticated user so the SPA can make authenticated API
 * calls to /api/admin/* endpoints.
 */
#[AsController]
class AdminController extends AppController
{
	#[Route('/admin', name: 'admin_home', methods: ['GET'])]
	#[Route('/admin/{path}', name: 'admin_spa', requirements: ['path' => '.*'], methods: ['GET'])]
	public function home(ApiTokenManager $apiTokenManager, string $path = ''): Response
	{
		$user = $this->getUser();
		$apiToken = null;

		if (null !== $user && $user instanceof \iikiti\CMS\Entity\Object\User) {
			$apiToken = $apiTokenManager->getOrCreateToken($user)->getToken();
		}

		return $this->render('admin/layout.twig', [
			'doc' => ['title' => 'Admin — iikiti'],
			'api_token' => $apiToken ?? '',
			'api_base' => '/api',
			'debug' => $this->getParameter('kernel.debug') ? 'true' : 'false',
		]);
	}
}
