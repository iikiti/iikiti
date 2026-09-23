<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Repository\Object\PageRepository;
use iikiti\CMS\Web\BlockEditor\PageRendering;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Object controller.
 *
 * Handles individual object pages (e.g. Pages) rendered through the front-end
 * block editor pipeline.
 */
#[AsController]
class ObjectController extends AppController
{
	public function __construct(
		private readonly PageRendering $pageRendering,
		private readonly PageRepository $pageRepository,
	) {
	}

	#[Route('/{slug}', name: 'page', requirements: ['slug' => '[\w\d_-]+'], methods: ['GET', 'HEAD'], priority: -100)]
	public function page(Request $request, string $slug): Response
	{
		$page = $this->pageRepository->findOneByProperty('slug', $slug);

		if (!$page instanceof \iikiti\CMS\Entity\Object\Page) {
			throw new NotFoundHttpException(sprintf('Page "%s" not found.', $slug));
		}

		return $this->pageRendering->render($request, $page);
	}
}
