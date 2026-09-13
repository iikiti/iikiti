<?php

namespace iikiti\CMS\Tests\Authentication;

use iikiti\CMS\Authentication\MfaUserConfiguration;
use PHPUnit\Framework\TestCase;

final class MfaUserConfigurationTest extends TestCase
{
	public function testEmptyConfigurationIsDisabled(): void
	{
		$configuration = new MfaUserConfiguration([]);

		$this->assertFalse($configuration->isEnabled());
		$this->assertSame([], $configuration->getEnabledMethods());
		$this->assertNull($configuration->getTotpSecret());
		$this->assertSame([], $configuration->getBackupCodeHashes());
	}

	public function testEnabledConfigurationExposesValues(): void
	{
		$configuration = new MfaUserConfiguration([
			MfaUserConfiguration::KEY_ENABLED => true,
			MfaUserConfiguration::KEY_METHODS => ['email', 'totp'],
			MfaUserConfiguration::KEY_TOTP_SECRET => 'secret-value',
			MfaUserConfiguration::KEY_BACKUP_CODES => ['hash-one', 'hash-two', ''],
		]);

		$this->assertTrue($configuration->isEnabled());
		$this->assertSame(['email', 'totp'], $configuration->getEnabledMethods());
		$this->assertTrue($configuration->hasMethod('email'));
		$this->assertFalse($configuration->hasMethod('backup_code'));
		$this->assertSame('secret-value', $configuration->getTotpSecret());
		$this->assertSame(['hash-one', 'hash-two'], $configuration->getBackupCodeHashes());
	}

	public function testInvalidMethodAndSecretValuesAreIgnored(): void
	{
		$configuration = new MfaUserConfiguration([
			MfaUserConfiguration::KEY_ENABLED => 'yes',
			MfaUserConfiguration::KEY_METHODS => 'not-an-array',
			MfaUserConfiguration::KEY_TOTP_SECRET => '',
			MfaUserConfiguration::KEY_BACKUP_CODES => 'not-an-array',
		]);

		$this->assertFalse($configuration->isEnabled());
		$this->assertSame([], $configuration->getEnabledMethods());
		$this->assertNull($configuration->getTotpSecret());
		$this->assertSame([], $configuration->getBackupCodeHashes());
	}
}
