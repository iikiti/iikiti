<?php

namespace iikiti\CMS\Plugin;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runtime registry of plugins loaded into the current kernel.
 *
 * Populated from the `iikiti.plugins` container parameter, which the kernel sets
 * during compilation from the active plugins discovered at boot. Consumed by
 * management commands, the admin API and other services that need to enumerate
 * installed plugins.
 */
class PluginRegistry
{
	/** @var array<string,PluginManifest> */
	private array $manifests = [];

	/**
	 * @param array<string,mixed> $plugins plugin metadata keyed by slug
	 */
	public function __construct(
		#[Autowire('%iikiti.plugins%')]
		array $plugins = []
	) {
		foreach ($plugins as $plugin) {
			if (!is_array($plugin) || !isset($plugin['manifest']) || !is_array($plugin['manifest'])) {
				continue;
			}
			try {
				$this->add(PluginManifest::fromArray($plugin['manifest']));
			} catch (\Throwable) {
				// Invalid manifests are rejected earlier by the loader; ignore
				// here so a broken entry cannot break the whole container.
				continue;
			}
		}
	}

	public function add(PluginManifest $manifest): void
	{
		$this->manifests[$manifest->slug] = $manifest;
	}

	public function has(string $slug): bool
	{
		return isset($this->manifests[$slug]);
	}

	public function count(): int
	{
		return count($this->manifests);
	}
}
