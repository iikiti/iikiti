<?php

namespace iikiti\CMS\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\ApplicationRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\MfaBundle\Authentication\Enum\ConfigurationTypeEnum;
use iikiti\MfaBundle\Authentication\Interface\MfaConfigurationServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class MfaConfigurationService implements MfaConfigurationServiceInterface
{
	public function __construct(
		private EntityManagerInterface $entityManager,
		private RequestStack $requestStack
	) {
	}

	public function getMultifactorPreferences(ConfigurationTypeEnum $type): array
	{
		/** @var ApplicationRepository $appRep */
		$appRep = $this->entityManager->getRepository(Application::class);
		/** @var SiteRepository $siteRep */
		$siteRep = $this->entityManager->getRepository(Site::class);
		/** @var User|null $user */
		$user = $this->requestStack->getCurrentRequest()?->getUser();

		return match ($type) {
			ConfigurationTypeEnum::APPLICATION => $appRep->getCurrentApplication()?->
				getMultifactorPreferences() ?? [],
			ConfigurationTypeEnum::SITE => $siteRep->getCurrent()?->
				getMultifactorPreferences() ?? [],
			ConfigurationTypeEnum::USER => $user?->getMultifactorPreferences() ?? [],
			default => throw new \Exception('Unknown configuration type: '.$type->name)
		};
	}

	public function setMultifactorPreferences(
		ConfigurationTypeEnum $type,
		array $preferences
	): void {
	}
}
