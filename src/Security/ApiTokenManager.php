<?php

namespace iikiti\CMS\Security;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\ApiToken;
use iikiti\CMS\Entity\Object\User;

/**
 * Manages ephemeral API tokens for authenticated users accessing admin endpoints.
 *
 * When a user loads the admin UI, the controller calls {@see getOrCreateToken()}
 * to obtain a non-expired API token. The token is short-lived and refreshed
 * on each page load. Expired tokens are cleaned up automatically.
 */
class ApiTokenManager
{
	public const TOKEN_TTL_SECONDS = 3600;

	public function __construct(
		private readonly EntityManagerInterface $entityManager,
	) {
	}

	public function getOrCreateToken(User $user): ApiToken
	{
		$existing = $this->entityManager
			->getRepository(ApiToken::class)
			->findOneBy(['user' => $user]);

		if (null !== $existing && !$existing->isExpired()) {
			return $existing;
		}

		if (null !== $existing) {
			$this->entityManager->remove($existing);
			$this->entityManager->flush();
		}

		$token = new ApiToken();
		$token->setToken(bin2hex(random_bytes(32)));
		$token->setUser($user);
		$token->setExpiresAt(new \DateTimeImmutable(sprintf('+%d seconds', self::TOKEN_TTL_SECONDS)));

		$this->entityManager->persist($token);
		$this->entityManager->flush();

		return $token;
	}
}
