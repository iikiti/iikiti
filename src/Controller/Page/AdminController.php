<?php

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

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
			],
			new Response(
				'',
				Response::HTTP_OK,
				['content-type' => 'text/html']
			)
		);
	}
}
