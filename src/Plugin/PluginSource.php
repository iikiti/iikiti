<?php

namespace iikiti\CMS\Plugin;

/**
 * Where a plugin package was obtained from.
 *
 * First-party bundles shipped with the application and verified iikiti-store
 * packages are trusted. Third-party sources are limited to development/testing
 * environments (see PluginDownloader).
 */
enum PluginSource: string
{
	/** Shipped in the application's built-in plugin directory. */
	case BuiltIn = 'built_in';

	/** The official (or iikiti-reskinned) store infrastructure. */
	case IikitiStore = 'iikiti_store';

	/** A third-party store or URL. Development/testing only. */
	case ThirdParty = 'third_party';

	/** Copied into place manually (e.g. by a developer). */
	case Manual = 'manual';

	public function isTrusted(): bool
	{
		return self::IikitiStore === $this;
	}
}
