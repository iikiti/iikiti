<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration controller.
 */
#[AsController]
class AdminController extends AppController
{
	#[Route('/admin', name: 'admin_home')]
	public function home(): Response
	{
		return $this->render(
			'index.twig',
			[
				'doc' => ['title' => 'iikiti'],
			]
		);
	}
}
