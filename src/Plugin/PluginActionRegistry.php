<?php

namespace iikiti\CMS\Plugin;

use iikiti\CMS\Entity\Object\Site;

/**
 * Plugin-facing read API for installed action declarations and site activation.
 */
final readonly class PluginActionRegistry
{
	public function __construct(
		private PluginManager $pluginManager,
		private PluginActionMatcher $matcher,
	) {
	}

	/** @return array<string,PluginManifest> */
	public function getInstalledPlugins(): array
	{
		$manifests = [];
		foreach ($this->pluginManager->getInstalled() as $slug => $plugin) {
			$manifests[$slug] = $plugin['manifest'];
		}

		return $manifests;
	}

	/** @return array<string,PluginManifest> */
	public function getActivePluginsForSite(Site $site): array
	{
		$installed = $this->getInstalledPlugins();
		$active = [];
		foreach ($site->getConfiguration()->getActivePlugins() as $slug) {
			if (isset($installed[$slug])) {
				$active[$slug] = $installed[$slug];
			}
		}

		return $active;
	}

	/**
	 * Find plugins claiming an exact action ID. Pass a site to restrict results to
	 * plugins enabled there; omit it to inspect all installed declarations.
	 *
	 * @return list<PluginManifest>
	 */
	public function findClaimants(string $actionId, ?Site $site = null): array
	{
		$plugins = null === $site ? $this->getInstalledPlugins() : $this->getActivePluginsForSite($site);

		return $this->matcher->findClaimants($plugins, $actionId);
	}

	/**
	 * Find declared hard conflicts between a plugin and the plugins active on a site.
	 * The candidate itself need not already be active on that site.
	 *
	 * @return list<PluginActionConflict>
	 */
	public function findConflicts(string $pluginSlug, Site $site): array
	{
		$candidate = $this->getInstalledPlugins()[$pluginSlug] ?? null;
		if (!$candidate instanceof PluginManifest) {
			throw new \InvalidArgumentException(sprintf('Plugin "%s" is not installed.', $pluginSlug));
		}

		return $this->matcher->findConflicts($candidate, $this->getActivePluginsForSite($site));
	}
}
