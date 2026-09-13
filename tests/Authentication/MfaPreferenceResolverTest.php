<?php

namespace iikiti\CMS\Tests\Authentication;

use iikiti\CMS\Authentication\Enum\ConfigurationTypeEnum;
use iikiti\CMS\Authentication\MfaConfigurationServiceInterface;
use iikiti\CMS\Authentication\MfaPreferenceResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

final class MfaPreferenceResolverTest extends TestCase
{
	public function testUserPreferencesOverrideLowerLevels(): void
	{
		$service = $this->createStub(MfaConfigurationServiceInterface::class);
		$service->method('getMultifactorPreferences')->willReturnCallback(
			static fn (ConfigurationTypeEnum $type): array => match ($type) {
				ConfigurationTypeEnum::APPLICATION => ['enabled' => true, 'methods' => ['email']],
				ConfigurationTypeEnum::SITE => ['methods' => ['totp']],
				ConfigurationTypeEnum::USER => ['methods' => ['backup_code']],
			}
		);

		$configuration = (new MfaPreferenceResolver($service))->resolve(
			$this->createStub(UserInterface::class)
		);

		$this->assertTrue($configuration->isEnabled());
		$this->assertSame(['backup_code'], $configuration->getEnabledMethods());
	}

	public function testLevelsMergeKeysWhenUserDoesNotOverrideThem(): void
	{
		$service = $this->createStub(MfaConfigurationServiceInterface::class);
		$service->method('getMultifactorPreferences')->willReturnCallback(
			static fn (ConfigurationTypeEnum $type): array => match ($type) {
				ConfigurationTypeEnum::APPLICATION => ['enabled' => true, 'methods' => ['email']],
				ConfigurationTypeEnum::SITE => [],
				ConfigurationTypeEnum::USER => [],
			}
		);

		$configuration = (new MfaPreferenceResolver($service))->resolve(
			$this->createStub(UserInterface::class)
		);

		$this->assertTrue($configuration->isEnabled());
		$this->assertSame(['email'], $configuration->getEnabledMethods());
	}
}
