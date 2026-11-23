<?php

declare(strict_types=1);

namespace iikiti\CMS\Admin;

/**
 * Optional extension of {@see AdminExtensionInterface} for extensions owned by
 * a specific plugin slug, allowing {@see AdminMenuRegistry} to gate site-scoped
 * menus and screens by plugin activation.
 */
interface PluginOwnedAdminExtensionInterface extends AdminExtensionInterface
{
	public function getPluginSlug(): string;
}
