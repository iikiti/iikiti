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

	/** @var array<string,string> */
	private array $paths = [];

	/**
	 * @param array<string,mixed> $plugins plugin metadata keyed by slug
	 */
	public function __construct(
		#[Autowire('%iikiti.plugins%')]
		array $plugins = [],
	) {
		foreach ($plugins as $plugin) {
			if (!is_array($plugin) || !isset($plugin['manifest']) || !is_array($plugin['manifest'])) {
				continue;
			}
			try {
				$manifest = PluginManifest::fromArray($plugin['manifest']);
				$this->manifests[$manifest->slug] = $manifest;
				$this->paths[$manifest->slug] = (string) ($plugin['path'] ?? '');
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

	/**
	 * @return array<string,PluginManifest>
	 */
	public function getManifests(): array
	{
		return $this->manifests;
	}

	/**
	 * Returns the filesystem path of an active plugin, or null if the
	 * plugin is not active.
	 */
	public function getPath(string $slug): ?string
	{
		if (!$this->has($slug)) {
			return null;
		}

		return $this->paths[$slug] ?: null;
	}
}
