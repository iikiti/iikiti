<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/** Saves a user's preferred BCP 47 locale. */
#[AsController]
final class UserLocaleController
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly Security $security,
	) {
	}

	#[Route('/api/user/locale', name: 'api_user_locale', methods: ['POST'])]
	public function save(Request $request): JsonResponse
	{
		$user = $this->security->getUser();
		if (!$user instanceof User) {
			return new JsonResponse(['error' => 'Authentication required.'], JsonResponse::HTTP_UNAUTHORIZED);
		}

		$payload = json_decode($request->getContent(), true);
		if (!is_array($payload) || !array_key_exists('locale', $payload)) {
			return new JsonResponse(['error' => 'A locale string or null is required.'], JsonResponse::HTTP_BAD_REQUEST);
		}
		$locale = $payload['locale'];
		if (null !== $locale && !is_string($locale)) {
			return new JsonResponse(['error' => 'locale must be a BCP 47 string or null.'], JsonResponse::HTTP_BAD_REQUEST);
		}

		try {
			$user->setPreferredLocale($locale);
		} catch (\InvalidArgumentException $exception) {
			return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
		}

		$this->entityManager->flush();

		return new JsonResponse(['locale' => $user->getPreferredLocale()?->getTag()]);
	}
}
