<?php

declare(strict_types=1);

namespace iikiti\CMS\Admin;

/**
 * Default implementation of the optional methods on {@see AdminExtensionInterface}.
 *
 * Plugins that only contribute menu items and API resources can use this trait
 * to avoid implementing `getAdminScreens()` (which returns an empty list by
 * default). Plugins that contribute full admin screens implement the method
 * directly.
 */
trait AdminExtensionTrait
{
	/**
	 * Admin screens contributed by this extension.
	 *
	 * @return list<AdminScreen>
	 */
	public function getAdminScreens(): array
	{
		return [];
	}
}
