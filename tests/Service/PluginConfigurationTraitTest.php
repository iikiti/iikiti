<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Service\Configuration;
use PHPUnit\Framework\TestCase;

final class PluginConfigurationTraitTest extends TestCase
{
	private const DEFAULT_PLUGIN = 'iikiti-content-optimization';

	public function testDefaultPluginIsActiveForNewAndExistingConfigurations(): void
	{
		$newConfiguration = new Configuration([]);
		$existingConfiguration = new Configuration(['plugins' => ['active' => ['another-plugin']]]);

		self::assertTrue($newConfiguration->isPluginActive(self::DEFAULT_PLUGIN));
		self::assertSame([self::DEFAULT_PLUGIN], $newConfiguration->getActivePlugins());
		self::assertTrue($existingConfiguration->isPluginActive(self::DEFAULT_PLUGIN));
		self::assertSame([self::DEFAULT_PLUGIN, 'another-plugin'], $existingConfiguration->getActivePlugins());
	}

	public function testDefaultPluginCanBeDisabledAndEnabledWithoutChangingOtherConfiguration(): void
	{
		$configuration = new Configuration([
			'plugins' => [
				'active' => ['another-plugin'],
				'configuration' => ['another-plugin' => ['setting' => 'preserved']],
			],
			'extensions' => ['legacy-value' => 'preserved'],
			'theme' => 'forma-2026',
		]);

		$configuration->disablePlugin(self::DEFAULT_PLUGIN);

		self::assertFalse($configuration->isPluginActive(self::DEFAULT_PLUGIN));
		self::assertSame(['another-plugin'], $configuration->getActivePlugins());
		self::assertSame([self::DEFAULT_PLUGIN], $configuration->getPluginData()->inactive);

		$configuration->enablePlugin(self::DEFAULT_PLUGIN);
		$configuration->enablePlugin(self::DEFAULT_PLUGIN);

		self::assertTrue($configuration->isPluginActive(self::DEFAULT_PLUGIN));
		self::assertSame([self::DEFAULT_PLUGIN, 'another-plugin'], $configuration->getActivePlugins());
		self::assertSame([], $configuration->getPluginData()->inactive);
		self::assertSame(['legacy-value' => 'preserved'], $configuration->get('extensions'));
		self::assertSame('forma-2026', $configuration->get('theme'));
		self::assertSame(
			['another-plugin' => ['setting' => 'preserved']],
			(array) $configuration->getPluginData()->configuration,
		);
	}

	public function testDisablingAnOrdinaryPluginRemovesOnlyThatPluginFromActiveList(): void
	{
		$configuration = new Configuration(['plugins' => ['active' => ['first-plugin', 'second-plugin']]]);

		$configuration->disablePlugin('first-plugin');

		self::assertSame([self::DEFAULT_PLUGIN, 'second-plugin'], $configuration->getActivePlugins());
		self::assertFalse($configuration->isPluginActive('first-plugin'));
	}
}
