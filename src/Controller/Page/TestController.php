<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test controller.
 *
 * Handles tests. Won't be included in final.
 */
#[AsController]
class TestController extends AppController
{
	#[Route('/test', name: 'test', priority: 0)]
	public function index(): Response
	{
		return $this->render(
			'index.twig',
			['doc' => ['title' => 'iikiti']]
		);
	}
}
