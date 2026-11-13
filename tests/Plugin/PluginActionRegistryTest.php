<?php

namespace iikiti\CMS\Tests\Plugin;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Plugin\PluginActionMatcher;
use iikiti\CMS\Plugin\PluginActionRegistry;
use iikiti\CMS\Plugin\PluginManager;
use iikiti\CMS\Plugin\PluginManifest;
use iikiti\CMS\Service\Configuration;
use PHPUnit\Framework\TestCase;

final class PluginActionRegistryTest extends TestCase
{
	public function testDistinguishesInstalledFromSiteActiveActionClaimants(): void
	{
		$active = $this->manifest('security-active', ['security.firewall.inspect.sqli']);
		$inactive = $this->manifest('security-inactive', ['security.firewall.inspect.sqli']);
		$manager = $this->createStub(PluginManager::class);
		$manager->method('getInstalled')->willReturn([
			$active->slug => ['manifest' => $active],
			$inactive->slug => ['manifest' => $inactive],
		]);
		$registry = new PluginActionRegistry($manager, new PluginActionMatcher());
		$site = $this->createStub(Site::class);
		$configuration = $this->createStub(Configuration::class);
		$configuration->method('getActivePlugins')->willReturn([$active->slug]);
		$site->method('getConfiguration')->willReturn($configuration);

		self::assertSame(
			['security-active', 'security-inactive'],
			array_map(static fn (PluginManifest $plugin): string => $plugin->slug, $registry->findClaimants('security.firewall.inspect.sqli')),
		);
		self::assertSame(
			['security-active'],
			array_map(static fn (PluginManifest $plugin): string => $plugin->slug, $registry->findClaimants('security.firewall.inspect.sqli', $site)),
		);
	}

	/**
	 * @param list<string> $actions
	 * @param list<string> $conflicts
	 */
	private function manifest(string $slug, array $actions, array $conflicts = []): PluginManifest
	{
		return new PluginManifest(
			name: $slug,
			slug: $slug,
			namespace: 'Acme\\Plugin\\Test',
			bundleClass: 'Acme\\Plugin\\Test\\TestBundle',
			version: '1.0.0',
			iikitiVersion: '^1.0',
			actions: $actions,
			actionConflicts: $conflicts,
		);
	}
}
