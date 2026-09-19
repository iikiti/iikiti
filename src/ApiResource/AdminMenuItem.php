<?php

namespace iikiti\CMS\ApiResource;

/**
 * Read-only value object representing a menu item in the admin UI.
 *
 * Plugins contribute menu items via {@see \iikiti\CMS\Admin\AdminExtensionInterface}.
 */
class AdminMenuItem
{
	public function __construct(
		public string $label,
		public string $path,
		public ?string $icon = null,
		public ?string $badge = null,
		/** @var list<AdminMenuItem> */
		public array $children = [],
		public int $priority = 0,
	) {
	}

	/**
	 * @param list<AdminMenuItem> $children
	 */
	public static function create(
		string $label,
		string $path,
		?string $icon = null,
		int $priority = 0,
		array $children = [],
	): self {
		return new self($label, $path, $icon, null, $children, $priority);
	}
}
