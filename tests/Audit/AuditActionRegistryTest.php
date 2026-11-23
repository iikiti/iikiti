<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Audit;

use iikiti\CMS\Audit\AuditActionProviderInterface;
use iikiti\CMS\Audit\AuditActionRegistry;
use iikiti\CMS\Audit\AuditLogger;
use iikiti\CMS\Audit\AuditRecorder;
use PHPUnit\Framework\TestCase;

final class AuditActionRegistryTest extends TestCase
{
	public function testCollectsCoreAndPluginAuditActions(): void
	{
		$provider = new class () implements AuditActionProviderInterface {
			public function getAuditActions(): array
			{
				return ['custom_plugin_sync'];
			}
		};

		$registry = new AuditActionRegistry([$provider]);

		self::assertTrue($registry->isValid('created'));
		self::assertTrue($registry->isValid('custom_plugin_sync'));
		self::assertFalse($registry->isValid('nonexistent_action'));
		self::assertContains('custom_plugin_sync', $registry->all());

		$recorder = new AuditRecorder($this->createStub(AuditLogger::class), $registry);
		self::assertTrue($recorder->isSupportedAction('custom_plugin_sync'));
		self::assertFalse($recorder->isSupportedAction('unknown'));
	}

	public function testRejectsInvalidPluginActionFormat(): void
	{
		$provider = new class () implements AuditActionProviderInterface {
			public function getAuditActions(): array
			{
				return ['invalid-action-with-hyphens!'];
			}
		};

		$this->expectException(\InvalidArgumentException::class);
		new AuditActionRegistry([$provider]);
	}
}
