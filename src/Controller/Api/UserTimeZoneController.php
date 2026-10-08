<?php

namespace iikiti\CMS\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Saves the signed-in user's time zone preference.
 *
 * Validation lives on {@see User::setTimeZone()}, so only known IANA zones are
 * stored. Sending `null` clears the preference and falls back to the browser's
 * zone.
 */
#[AsController]
class UserTimeZoneController extends AbstractController
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
	) {
	}

	#[Route('/api/user/timezone', name: 'api_user_timezone', methods: ['POST'])]
	public function save(Request $request): JsonResponse
	{
		$user = $this->getUser();
		if (!$user instanceof User) {
			return new JsonResponse(['error' => 'Authentication required.'], JsonResponse::HTTP_UNAUTHORIZED);
		}

		$payload = json_decode($request->getContent(), true);
		$zone = is_array($payload) ? ($payload['timeZone'] ?? null) : null;
		if (null !== $zone && !is_string($zone)) {
			return new JsonResponse(['error' => 'timeZone must be a string or null.'], JsonResponse::HTTP_BAD_REQUEST);
		}

		try {
			$user->setTimeZone($zone);
		} catch (\InvalidArgumentException $e) {
			return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
		}

		$this->entityManager->flush();

		return new JsonResponse(['timeZone' => $user->getTimeZone()?->getId()]);
	}
}
