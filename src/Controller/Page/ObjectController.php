<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Object controller.
 *
 * Handles individual object pages.
 */
#[AsController]
class ObjectController extends AppController
{
	//#[Route('/{slug}', name: 'page', requirements: ['slug' => '[\w\d\_]+', 'page' => '\d+'], priority: 0)]
	public function page(Request $request): Response
	{

		return $this->render(
			'object.twig',
			['doc' => ['title' => 'iikiti']]
		);
	}
}
