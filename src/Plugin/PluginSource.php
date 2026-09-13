<?php

namespace iikiti\CMS\Plugin;

/**
 * Where a plugin package was obtained from.
 *
 * Only the iikiti store may be used on production servers. Third-party sources
 * are limited to development/testing environments (see PluginDownloader).
 */
enum PluginSource: string
{
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
