<?php

namespace iikiti\CMS\Authentication;

use iikiti\CMS\Authentication\Enum\ConfigurationTypeEnum;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Resolves the effective multi-factor preferences for a user.
 *
 * Application, site and user preferences are merged in that order so that
 * user settings override site settings, which override application settings.
 */
class MfaPreferenceResolver
{
	public function __construct(
		private readonly MfaConfigurationServiceInterface $configurationService,
	) {
	}

	public function resolve(UserInterface $user): MfaUserConfiguration
	{
		return new MfaUserConfiguration(array_replace(
			$this->configurationService->getMultifactorPreferences(ConfigurationTypeEnum::APPLICATION, $user),
			$this->configurationService->getMultifactorPreferences(ConfigurationTypeEnum::SITE, $user),
			$this->configurationService->getMultifactorPreferences(ConfigurationTypeEnum::USER, $user),
		));
	}
}
