<?php

namespace iikiti\CMS\Plugin\Lifecycle;

use iikiti\CMS\Plugin\PluginContext;

/**
 * Optional lifecycle hooks a plugin may implement.
 *
 * Hooks are invoked by {@see PluginLifecycleHandler} during the corresponding
 * management operation. They run once per site for batch (all-sites) operations.
 */
interface PluginLifecycleInterface
{
	/**
	 * Called after the plugin package is placed on disk for the first time.
	 */
	public function onPluginInstall(PluginContext $context): void;

	/**
	 * Called when the plugin is enabled for a site.
	 */
	public function onPluginActivate(PluginContext $context): void;

	/**
	 * Called when the plugin is disabled for a site.
	 */
	public function onPluginDeactivate(PluginContext $context): void;

	/**
	 * Called after the plugin is upgraded from one version to another.
	 */
	public function onPluginUpdate(PluginContext $context, string $fromVersion): void;

	/**
	 * Called after the plugin package has been removed.
	 */
	public function onPluginUninstall(PluginContext $context): void;
}
