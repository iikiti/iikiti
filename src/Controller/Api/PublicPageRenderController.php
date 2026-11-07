<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api;

use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\PageRepository;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\GuestApiUser;
use iikiti\CMS\Security\MfaAuthenticationGate;
use iikiti\CMS\Web\BlockEditor\PageRendering;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class PublicPageRenderController
{
	public function __construct(
		private PageRendering $pageRendering,
		private PageRepository $pages,
		private Security $security,
		private MfaAuthenticationGate $mfaGate,
	) {
	}

	#[Route('/api/public/render/home', name: 'api_public_render_home', methods: ['GET', 'HEAD'])]
	public function home(Request $request): JsonResponse
	{
		$this->assertPublicRenderAccess($request);
		$renderRequest = $this->renderRequest($request, '/');

		return $this->response($this->pageRendering->render($renderRequest, home: true));
	}

	#[Route('/api/public/render/pages/{slug}', name: 'api_public_render_page', requirements: ['slug' => '[\w\d_-]+'], methods: ['GET', 'HEAD'])]
	public function page(Request $request, string $slug): JsonResponse
	{
		$this->assertPublicRenderAccess($request);
		$page = $this->pages->findOneByProperty('slug', $slug);
		if (!$page instanceof Page) {
			throw new NotFoundHttpException(sprintf('Page "%s" not found.', $slug));
		}

		$renderRequest = $this->renderRequest($request, '/'.$slug);

		return $this->response($this->pageRendering->render($renderRequest, $page));
	}

	private function assertPublicRenderAccess(Request $request): void
	{
		$user = $this->security->getUser();
		if (!$user instanceof User && !$user instanceof GuestApiUser) {
			throw new AccessDeniedHttpException('A site-scoped API credential is required.');
		}
		if ($user instanceof User && !$this->mfaGate->allowsFullyAuthenticatedUser()) {
			throw new AccessDeniedHttpException('Complete the MFA challenge before rendering authenticated content.');
		}
		if ($user instanceof GuestApiUser
			&& 0 !== strcasecmp($user->getSiteHost(), (string) $request->headers->get('X-SITE-HOST'))) {
			throw new AccessDeniedHttpException('Guest API credential is scoped to another site.');
		}
		if ($user instanceof User) {
			$user->setCurrentSite(SiteRegistry::getCurrent());
		}
	}

	private function renderRequest(Request $request, string $path): Request
	{
		$host = (string) $request->headers->get('X-SITE-HOST', $request->getHost());
		$scheme = (string) $request->headers->get('X-SITE-SCHEME', 'https');
		if (!in_array($scheme, ['http', 'https'], true)) {
			$scheme = 'https';
		}
		$uri = $scheme.'://'.$host.$path;
		$query = $request->query->all();
		if ([] !== $query) {
			$uri .= '?'.http_build_query($query);
		}

		return Request::create($uri, 'GET', [], $request->cookies->all());
	}

	private function response(\Symfony\Component\HttpFoundation\Response $response): JsonResponse
	{
		return new JsonResponse(
			['html' => $response->getContent() ?: ''],
			$response->getStatusCode(),
			['Cache-Control' => 'private, no-store'],
		);
	}
}
