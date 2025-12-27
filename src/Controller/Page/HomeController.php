<?php

namespace iikiti\CMS\Controller\Page;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Controller\AppController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Homepage controller.
 */
#[AsController]
class HomeController extends AppController
{
	#[Route('/', name: 'home', priority: 0, format: 'html')]
	public function index(Request $request, EntityManagerInterface $em): Response
	{
		return $this->render(
			'index.twig',
			[
				'doc' => ['title' => 'iikiti'],
			]
		);
	}
}
