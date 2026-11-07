<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration controller.
 *
 * Serves the Svelte 5 admin SPA. The SPA exchanges its authenticated session
 * with the API token endpoint instead of persisting a token in this controller.
 */
#[AsController]
class AdminController extends AppController
{
	public function __construct(
		Security $security,
		ContainerInterface $container,
		#[Autowire('%env(string:INTERNAL_API_BASE_URL)%')]
		private readonly string $apiBaseUrl,
	) {
		parent::__construct($security, $container);
	}

	#[Route('/admin', name: 'admin_home', methods: ['GET'])]
	#[Route('/admin/{path}', name: 'admin_spa', requirements: ['path' => '.*'], methods: ['GET'])]
	public function home(Request $request, string $path = ''): Response
	{
		return $this->render('admin/layout.twig', [
			'doc' => ['title' => 'Admin — iikiti'],
			'api_token' => '',
			'api_base' => rtrim($this->apiBaseUrl, '/').'/api',
			'site_host' => strtolower($request->getHost()),
			'site_scheme' => $request->getScheme(),
			'time_zone' => '',
			'current_user' => '',
			'debug' => $this->getParameter('kernel.debug') ? 'true' : 'false',
		]);
	}
}
