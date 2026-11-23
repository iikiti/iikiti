<?php

namespace iikiti\CMS\Admin;

use iikiti\CMS\ApiResource\AdminApiResource;
use iikiti\CMS\ApiResource\AdminMenuItem;
use iikiti\CMS\ApiResource\AdminScreen;
use iikiti\CMS\Plugin\PluginActivationChecker;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects menu items and API resources from all registered
 * {@see AdminExtensionInterface} services.
 *
 * Tagged services must use the `iikiti.admin.extension` tag.
 */
class AdminMenuRegistry
{
	/**
	 * @param iterable<AdminExtensionInterface> $adminExtensions
	 */
	public function __construct(
		#[AutowireIterator('iikiti.admin.extension')]
		private iterable $adminExtensions,
		private readonly ?PluginActivationChecker $activationChecker = null,
	) {
	}

	/**
	 * @return list<AdminMenuItem>
	 */
	public function getMenu(): array
	{
		$items = [];

		foreach ($this->adminExtensions as $extension) {
			if (!$this->isExtensionActive($extension)) {
				continue;
			}
			foreach ($extension->getMenuItems() as $item) {
				$items[] = $item;
			}
		}

		usort($items, static fn (AdminMenuItem $a, AdminMenuItem $b): int => $b->priority <=> $a->priority);

		return $items;
	}

	/**
	 * @return list<AdminApiResource>
	 */
	public function getResources(): array
	{
		$resources = [];

		foreach ($this->adminExtensions as $extension) {
			if (!$this->isExtensionActive($extension)) {
				continue;
			}
			foreach ($extension->getResources() as $resource) {
				$resources[] = $resource;
			}
		}

		usort($resources, static fn (AdminApiResource $a, $b): int => $a->label <=> $b->label);

		return $resources;
	}

	/**
	 * Aggregates admin screens from all registered extensions.
	 *
	 * Screens are sorted by path for deterministic output to the SPA.
	 *
	 * @return list<AdminScreen>
	 */
	public function getScreens(): array
	{
		$screens = [];

		foreach ($this->adminExtensions as $extension) {
			if (!$this->isExtensionActive($extension)) {
				continue;
			}
			foreach ($extension->getAdminScreens() as $screen) {
				$screens[] = $screen;
			}
		}

		usort($screens, static fn (AdminScreen $a, AdminScreen $b): int => $a->path <=> $b->path);

		return $screens;
	}

	private function isExtensionActive(AdminExtensionInterface $extension): bool
	{
		if (null === $this->activationChecker) {
			return true;
		}

		$pluginSlug = null;
		if ($extension instanceof PluginOwnedAdminExtensionInterface) {
			$pluginSlug = $extension->getPluginSlug();
		} elseif (method_exists($extension, 'getPluginSlug')) {
			$resolved = $extension->getPluginSlug();
			$pluginSlug = is_string($resolved) && '' !== $resolved ? $resolved : null;
		}

		if (null === $pluginSlug) {
			return true;
		}

		return $this->activationChecker->isActiveForAdmin($pluginSlug);
	}
}
