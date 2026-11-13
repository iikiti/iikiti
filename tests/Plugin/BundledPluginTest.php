<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Plugin;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Plugin\PluginLoader;
use iikiti\CMS\Plugin\PluginSource;
use iikiti\CMS\Plugin\PluginValidator;
use iikiti\Extension\Forma2026\Forma2026Bundle;
use PHPUnit\Framework\TestCase;

final class BundledPluginTest extends TestCase
{
	public function testForma2026IsDiscoveredAsABuiltInThemeOnlyPlugin(): void
	{
		$loader = new PluginLoader(dirname(__DIR__, 2), new PluginValidator());
		$plugins = $loader->discover();
		$plugin = null;
		foreach ($plugins as $candidate) {
			if ('forma-2026' === $candidate['manifest']->slug) {
				$plugin = $candidate;
				break;
			}
		}

		self::assertNotNull($plugin);
		self::assertSame(PluginSource::BuiltIn, $plugin['installInfo']->source);
		self::assertTrue($plugin['allowReservedNamespace']);
		self::assertSame('plugins/forma-2026', substr($plugin['path'], strlen(dirname(__DIR__, 2)) + 1));

		$bundle = $loader->instantiate($plugin);
		self::assertInstanceOf(Forma2026Bundle::class, $bundle);
		self::assertSame('forma-2026', $bundle->getPluginSlug());
	}

	public function testBuiltInInstallMetadataDoesNotMakeAPluginStoreTrusted(): void
	{
		self::assertFalse(PluginSource::BuiltIn->isTrusted());
	}

	public function testForma2026IsRequiredForEverySite(): void
	{
		self::assertContains('forma-2026', (new Site())->getEnabledPlugins());
	}
}
