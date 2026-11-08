<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\PageRepository;
use iikiti\CMS\Security\MfaAuthenticationGate;
use iikiti\CMS\Web\BlockEditor\PageRendering;
use iikiti\CMS\Web\BlockEditor\PublicPageApiProxy;
use Psr\Container\ContainerInterface;
use Closure;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
	/** @param Closure():PageRendering $pageRendering */
	public function __construct(
		Security $security,
		ContainerInterface $container,
		#[AutowireServiceClosure(PageRendering::class)]
		private readonly Closure $pageRendering,
		private readonly PageRepository $pageRepository,
		private readonly PublicPageApiProxy $apiProxy,
		private readonly MfaAuthenticationGate $mfaGate,
		#[Autowire('%application_role%')]
		private readonly string $applicationRole,
		#[Autowire('%public_page_render_via_api%')]
		private readonly bool $publicPageRenderViaApi = false,
	) {
		parent::__construct($security, $container);
	}

	#[Route('/{slug}', name: 'page', requirements: ['slug' => '[\w\d_-]+'], methods: ['GET', 'HEAD'], priority: -100)]
	public function page(Request $request, string $slug): Response
	{
		if ('consumer' === $this->applicationRole) {
			return $this->apiProxy->page($request, $slug);
		}

		$user = $this->security->getUser();
		if ($user instanceof User && !$this->mfaGate->allowsFullyAuthenticatedUser()) {
			return new RedirectResponse('/api/auth/mfa/challenge');
		}
		if ($this->publicPageRenderViaApi) {
			return $this->apiProxy->page($request, $slug);
		}
		$page = $this->pageRepository->findOneByProperty('slug', $slug);
		if (!$page instanceof Page) {
			throw new NotFoundHttpException(sprintf('Page "%s" not found.', $slug));
		}

		return ($this->pageRendering)()->render($request, $page);
	}
}
