<?php

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\ApiResource\PluginInfo;
use iikiti\CMS\Plugin\PluginInstallInfo;
use iikiti\CMS\Plugin\PluginManager;
use iikiti\CMS\Plugin\PluginManifest;
use iikiti\CMS\Plugin\PluginRegistry;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides plugin data for the admin API.
 *
 * @implements ProviderInterface<PluginInfo>
 */
#[AutoconfigureTag('api_platform.state_provider')]
readonly class PluginProvider implements ProviderInterface
{
	public function __construct(
		private PluginManager $pluginManager,
		private PluginRegistry $pluginRegistry,
	) {
	}

	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
	{
		$installed = $this->pluginManager->getInstalled();
		$slug = $uriVariables['slug'] ?? null;

		if (null !== $slug) {
			if (!isset($installed[$slug])) {
				throw new NotFoundHttpException(sprintf('Plugin "%s" is not installed.', $slug));
			}

			return $this->toPluginInfo($installed[$slug], $this->pluginManager->getActiveSiteIdsByPlugin([$slug])[$slug] ?? []);
		}

		// Single pass over all sites, then reuse the map for every plugin.
		$activeByPlugin = $this->pluginManager->getActiveSiteIdsByPlugin();

		$plugins = [];
		foreach ($installed as $slug => $plugin) {
			$plugins[] = $this->toPluginInfo($plugin, $activeByPlugin[$slug] ?? []);
		}

		return $plugins;
	}

	/**
	 * @param array{manifest: PluginManifest, path: string, installInfo: ?PluginInstallInfo} $plugin
	 * @param list<string>                                                                   $activeSiteIds
	 */
	private function toPluginInfo(array $plugin, array $activeSiteIds): PluginInfo
	{
		$manifest = $plugin['manifest'];
		$installInfo = $plugin['installInfo'];

		return new PluginInfo(
			slug: $manifest->slug,
			name: $manifest->name,
			version: $manifest->version,
			edition: $manifest->edition,
			source: $installInfo?->source->value ?? 'manual',
			state: $installInfo?->state->value ?? 'pending_review',
			installed: true,
			active: $this->pluginRegistry->has($manifest->slug),
			activeSites: $activeSiteIds,
			description: $manifest->description,
			capabilities: $manifest->capabilities,
		);
	}
}
