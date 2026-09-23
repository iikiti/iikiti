<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Web\BlockEditor\PageRendering;
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
	public function __construct(private readonly PageRendering $pageRendering)
	{
	}

	#[Route('/', name: 'home', priority: 0, format: 'html')]
	public function index(Request $request): Response
	{
		return $this->pageRendering->render($request, home: true);
	}
}
