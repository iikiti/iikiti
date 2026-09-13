<?php

namespace iikiti\CMS\Plugin;

/**
 * Immutable context passed to plugin lifecycle hooks and management operations.
 *
 * Carries the plugin identity, the owning site (null for system-level
 * operations), the environment, and the installation source so that hooks can
 * behave correctly when operating across one or all sites.
 */
final readonly class PluginContext
{
	public function __construct(
		public string $slug,
		public string $version,
		public ?string $siteId = null,
		public string $environment = 'prod',
		public string $source = PluginSource::IikitiStore->value,
		public ?PluginState $state = null,
	) {
	}
}
