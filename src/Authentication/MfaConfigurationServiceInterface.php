<?php

namespace iikiti\CMS\Authentication;

use iikiti\CMS\Authentication\Enum\ConfigurationTypeEnum;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Provides multi-factor preferences for each configuration level.
 */
#[AutoconfigureTag('mfa.config')]
interface MfaConfigurationServiceInterface
{
	/**
	 * @return array<string,mixed>
	 */
	public function getMultifactorPreferences(ConfigurationTypeEnum $type, UserInterface $user): array;

	/**
	 * @param array<string,mixed> $preferences
	 */
	public function setMultifactorPreferences(
		ConfigurationTypeEnum $type,
		array $preferences,
	): void;
}
