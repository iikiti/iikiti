<?php

namespace iikiti\CMS\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\MfaBundle\Authentication\Enum\ConfigurationTypeEnum;
use iikiti\MfaBundle\Authentication\Interface\MfaConfigurationServiceInterface;
use Override;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Service to provide multi-factor authentication bundle the necessary
 * settings/preferences.
 */
class MfaConfigurationService implements MfaConfigurationServiceInterface
{
	public function __construct(
		private EntityManagerInterface $entityManager,
		private RequestStack $requestStack
	) {
	}

	#[Override]
	public function getMultifactorPreferences(
		ConfigurationTypeEnum $type,
		UserInterface $user
	): array {
		$appRep = $this->entityManager->getRepository(Application::class);
		$siteRep = $this->entityManager->getRepository(Site::class);

		return match ($type) {
			ConfigurationTypeEnum::APPLICATION => $appRep->getCurrentApplication()?->
				getMultifactorPreferences() ?? [],
			ConfigurationTypeEnum::SITE => $siteRep->getCurrent()?->
				getMultifactorPreferences() ?? [],
			ConfigurationTypeEnum::USER => self::__checkGetUserPreferences($user),
			default => throw new \Exception('Unknown configuration type: '.$type->name)
		};
	}

	private static function __checkGetUserPreferences(UserInterface $user): array
	{
		if (!($user instanceof User)) {
			throw new AuthenticationException('User is invalid');
		}

		return $user->getMultifactorPreferences() ?? [];
	}

	#[Override]
	public function setMultifactorPreferences(
		ConfigurationTypeEnum $type,
		array $preferences
	): void {
	}
}
