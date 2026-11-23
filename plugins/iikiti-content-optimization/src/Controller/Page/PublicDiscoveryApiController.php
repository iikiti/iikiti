<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Controller\Page;

use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\GuestApiUser;
use iikiti\CMS\Security\MfaAuthenticationGate;
use iikiti\Extension\ContentOptimization\Service\PublicDiscoveryDocumentGenerator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class PublicDiscoveryApiController
{
	public function __construct(
		private Security $security,
		private MfaAuthenticationGate $mfaGate,
		private PublicDiscoveryDocumentGenerator $documents,
	) {
	}

	#[Route('/api/public/content-optimization/robots', name: 'api_public_render_content_optimization_robots', methods: ['GET'])]
	public function robots(Request $request): JsonResponse
	{
		$this->assertPublicAccess($request);

		return $this->jsonDocument($this->documents->robots($request));
	}

	#[Route('/api/public/content-optimization/sitemap', name: 'api_public_render_content_optimization_sitemap', methods: ['GET'])]
	public function sitemap(Request $request): JsonResponse
	{
		$this->assertPublicAccess($request);

		return $this->jsonDocument($this->documents->sitemap($request));
	}

	private function assertPublicAccess(Request $request): void
	{
		$user = $this->security->getUser();
		if (!$user instanceof User && !$user instanceof GuestApiUser) {
			throw new AccessDeniedHttpException('A site-scoped API credential is required.');
		}
		if ($user instanceof GuestApiUser && 0 !== strcasecmp($user->getSiteHost(), (string) $request->headers->get('X-SITE-HOST'))) {
			throw new AccessDeniedHttpException('Guest API credential is scoped to another site.');
		}
		if ($user instanceof User && !$this->mfaGate->allowsFullyAuthenticatedUser()) {
			throw new AccessDeniedHttpException('Complete the MFA challenge before accessing public content.');
		}
		if ($user instanceof User && SiteRegistry::hasCurrent()) {
			$user->setCurrentSite(SiteRegistry::getCurrent());
		}
	}

	/** @param array{body:string,contentType:string} $document */
	private function jsonDocument(array $document): JsonResponse
	{
		return new JsonResponse($document, Response::HTTP_OK, ['Cache-Control' => 'public, max-age=300']);
	}
}
