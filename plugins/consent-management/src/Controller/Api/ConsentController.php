<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\Controller\Api;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Plugin\Attribute\RequiresPlugin;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\Extension\ConsentManagement\Service\ConsentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Consent management API: record and retrieve consent preferences.
 *
 * Public (unauthenticated) endpoints keyed by authenticated user or a
 * client-generated session id.
 */
#[AsController]
#[RequiresPlugin('consent-management')]
final class ConsentController extends AbstractController
{
	public function __construct(private readonly ConsentService $service)
	{
	}

	#[Route('/api/privacy/consent', name: 'api_privacy_consent', methods: ['GET'])]
	public function get(Request $request): JsonResponse
	{
		return new JsonResponse($this->service->getState($request, $this->site()));
	}

	#[Route('/api/privacy/consent', name: 'api_privacy_consent_record', methods: ['POST'])]
	public function record(Request $request): JsonResponse
	{
		$data = json_decode($request->getContent(), true);
		if (!is_array($data)) {
			return new JsonResponse(['error' => 'Invalid JSON body.'], JsonResponse::HTTP_BAD_REQUEST);
		}

		$site = $this->site();
		try {
			$result = $this->service->record($data, $request, $site);
		} catch (AccessDeniedHttpException) {
			return new JsonResponse(['error' => 'A valid consent session is required.'], JsonResponse::HTTP_FORBIDDEN);
		}

		$response = new JsonResponse($result, JsonResponse::HTTP_OK);
		$sessionId = $result['sessionId'] ?? null;
		if (is_string($sessionId) && '' !== $sessionId) {
			$signed = $this->service->signSessionId($sessionId, $site);
			if (null !== $signed) {
				$response->headers->setCookie(
					Cookie::create(ConsentService::SESSION_COOKIE_NAME)
						->withValue($signed)
						->withExpires(time() + 31_536_000)
						->withPath('/')
						->withSecure($request->isSecure())
						->withHttpOnly(true)
						->withSameSite(Cookie::SAMESITE_LAX),
				);
			}
		}

		return $response;
	}

	private function site(): Site
	{
		if (!SiteRegistry::hasCurrent()) {
			throw new NotFoundHttpException('No site.');
		}

		return SiteRegistry::getCurrent();
	}
}
