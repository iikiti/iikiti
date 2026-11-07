<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\State\Processor;

use ApiPlatform\Metadata\Post;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\PluginConfigurationOperation;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Plugin\PluginManager;
use iikiti\CMS\Service\Configuration;
use iikiti\CMS\State\Processor\PluginConfigurationProcessor;
use PHPUnit\Framework\TestCase;

final class PluginConfigurationProcessorTest extends TestCase
{
	public function testPerSiteConfigurationIsUpdatedByTheApiOperation(): void
	{
		$configuration = new Configuration(['plugins' => ['configuration' => ['acme-blog' => ['remove-me' => 'old', 'keep' => 'yes']]]]);
		$site = $this->createMock(Site::class);
		$site->method('getId')->willReturn(15);
		$site->method('getConfiguration')->willReturn($configuration);
		$site->expects(self::once())->method('setConfiguration');

		$manager = $this->createStub(PluginManager::class);
		$manager->method('getInstalled')->willReturn(['acme-blog' => []]);
		$manager->method('getSites')->willReturn([$site]);
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::once())->method('flush');

		$operation = new PluginConfigurationOperation(
			slug: 'acme-blog',
			siteId: '15',
			set: ['new=value=with=equals'],
			unset: ['remove-me'],
		);
		$result = (new PluginConfigurationProcessor($manager, $entityManager))->process(
			$operation,
			new Post(name: 'admin_plugin_configure'),
		);

		self::assertSame(['keep' => 'yes', 'new' => 'value=with=equals'], $result->configuration);
		self::assertTrue($result->success);
	}

	public function testLegacyConfigurationMigrationPreservesPluginValues(): void
	{
		$configuration = new Configuration([
			'extensions' => ['legacy-only' => true, 'overridden' => 'legacy'],
			'plugins' => ['overridden' => 'current'],
		]);
		$site = $this->createMock(Site::class);
		$site->method('getConfiguration')->willReturn($configuration);
		$site->expects(self::once())->method('setConfiguration')->with(self::callback(
			static function (Configuration $updated): bool {
				$json = (array) $updated->getJson();
				self::assertArrayNotHasKey('extensions', $json);
				self::assertSame(['legacy-only' => true, 'overridden' => 'current'], $json['plugins']);

				return true;
			},
		));

		$manager = $this->createStub(PluginManager::class);
		$manager->method('getSites')->willReturn([$site]);
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->expects(self::once())->method('flush');

		$operation = new PluginConfigurationOperation();
		$result = (new PluginConfigurationProcessor($manager, $entityManager))->process(
			$operation,
			new Post(name: 'admin_plugin_migrate_configuration'),
		);

		self::assertTrue($result->success);
		self::assertSame(['migratedSites' => 1], $result->result);
	}
}
