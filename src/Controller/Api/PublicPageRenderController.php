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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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

	#[Route('/api/public/render/home/manifest', name: 'api_public_render_home_manifest', methods: ['GET', 'HEAD'])]
	public function homeManifest(Request $request): JsonResponse
	{
		$this->assertPublicRenderAccess($request);

		return $this->manifest($request, '/', home: true);
	}

	#[Route('/api/public/render/pages/{slug}/manifest', name: 'api_public_render_page_manifest', requirements: ['slug' => '[\w\d_-]+'], methods: ['GET', 'HEAD'])]
	public function pageManifest(Request $request, string $slug): JsonResponse
	{
		$this->assertPublicRenderAccess($request);
		$page = $this->pages->findOneByProperty('slug', $slug);
		if (!$page instanceof Page) {
			throw new NotFoundHttpException(sprintf('Page "%s" not found.', $slug));
		}

		return $this->manifest($request, '/'.$slug, $page);
	}

	#[Route('/api/public/render/home/fragments/{kind}', name: 'api_public_render_home_fragment', requirements: ['kind' => 'region|shell|widget'], methods: ['GET', 'HEAD'])]
	public function homeFragment(Request $request, string $kind): JsonResponse
	{
		$this->assertPublicRenderAccess($request);

		return $this->fragment($request, '/', $kind, home: true);
	}

	#[Route('/api/public/render/pages/{slug}/fragments/{kind}', name: 'api_public_render_page_fragment', requirements: ['slug' => '[\w\d_-]+', 'kind' => 'region|shell|widget'], methods: ['GET', 'HEAD'])]
	public function pageFragment(Request $request, string $slug, string $kind): JsonResponse
	{
		$this->assertPublicRenderAccess($request);
		$page = $this->pages->findOneByProperty('slug', $slug);
		if (!$page instanceof Page) {
			throw new NotFoundHttpException(sprintf('Page "%s" not found.', $slug));
		}

		return $this->fragment($request, '/'.$slug, $kind, $page);
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

	private function renderRequest(Request $request, string $path, bool $stripFragmentParameters = false): Request
	{
		$host = (string) $request->headers->get('X-SITE-HOST', $request->getHost());
		$scheme = (string) $request->headers->get('X-SITE-SCHEME', 'https');
		if (!in_array($scheme, ['http', 'https'], true)) {
			$scheme = 'https';
		}
		$uri = $scheme.'://'.$host.$path;
		$query = $request->query->all();
		if ($stripFragmentParameters) {
			unset($query['_iikiti_fragment_id'], $query['_iikiti_render_revision']);
		}
		if ([] !== $query) {
			$uri .= '?'.http_build_query($query);
		}

		return Request::create($uri, 'GET', [], $request->cookies->all());
	}

	private function manifest(Request $request, string $path, ?Page $page = null, bool $home = false): JsonResponse
	{
		$renderRequest = $this->renderRequest($request, $path);

		return new JsonResponse(
			$this->pageRendering->renderManifest($renderRequest, $page, $home),
			Response::HTTP_OK,
			['Cache-Control' => 'private, no-store'],
		);
	}

	private function fragment(Request $request, string $path, string $kind, ?Page $page = null, bool $home = false): JsonResponse
	{
		$id = $request->query->get('_iikiti_fragment_id');
		$revision = $request->query->get('_iikiti_render_revision');
		if (!is_string($id) || '' === $id || strlen($id) > 128
			|| (null !== $revision && 1 !== preg_match('/^[a-f0-9]{64}$/D', $revision))) {
			throw new BadRequestHttpException('A valid page fragment identifier is required.');
		}

		$renderRequest = $this->renderRequest($request, $path, stripFragmentParameters: true);
		$fragment = $this->pageRendering->renderFragment(
			$renderRequest,
			$page,
			$home,
			$kind,
			$id,
			is_string($revision) ? $revision : null,
		);
		if (null === $fragment) {
			if ('region' === $kind && '@main' === $id && null === $revision) {
				return new JsonResponse(['found' => false], Response::HTTP_OK, ['Cache-Control' => 'private, no-store']);
			}
			throw new NotFoundHttpException('The requested page fragment does not exist.');
		}
		if (is_string($revision) && !hash_equals($fragment['revision'], $revision)) {
			return new JsonResponse(
				['message' => 'The page changed while its fragments were being rendered.'],
				Response::HTTP_CONFLICT,
				['Cache-Control' => 'private, no-store'],
			);
		}
		if (false === ($fragment['found'] ?? true)) {
			throw new NotFoundHttpException('The requested page fragment does not exist.');
		}

		return new JsonResponse(
			['found' => true, 'id' => $fragment['id'], 'revision' => $fragment['revision'], 'html' => $fragment['html'], 'styles' => $fragment['styles']],
			Response::HTTP_OK,
			['Cache-Control' => 'private, no-store'],
		);
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
