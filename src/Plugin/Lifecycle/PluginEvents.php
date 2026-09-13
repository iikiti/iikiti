<?php

namespace iikiti\CMS\Plugin\Lifecycle;

/**
 * Lifecycle event names dispatched by the plugin manager.
 *
 * These are dispatched once per site for batch operations (with the site id in
 * the event context) and once for system-level operations.
 */
final class PluginEvents
{
	/** A plugin package was installed for the first time. */
	public const INSTALL = 'iikiti.plugin.install';

	/** A plugin was enabled for a site (or all sites). */
	public const ACTIVATE = 'iikiti.plugin.activate';

	/** A plugin was disabled for a site (or all sites). */
	public const DEACTIVATE = 'iikiti.plugin.deactivate';

	/** A plugin was upgraded to a new version. */
	public const UPDATE = 'iikiti.plugin.update';

	/** A plugin package was removed. */
	public const UNINSTALL = 'iikiti.plugin.uninstall';

	private function __construct()
	{
	}
}
