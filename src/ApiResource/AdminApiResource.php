<?php

namespace iikiti\CMS\ApiResource;

/**
 * Value object describing an API resource contributed by a plugin to the
 * admin UI.
 *
 * Used by {@see \iikiti\CMS\Admin\AdminExtensionInterface::getResources()}
 * so the admin SPA can discover plugin-provided endpoints.
 */
class AdminApiResource
{
	public function __construct(
		public string $name,
		public string $path,
		public string $label,
		public ?string $icon = null,
	) {
	}

	public static function create(string $name, string $path, string $label, ?string $icon = null): self
	{
		return new self($name, $path, $label, $icon);
	}
}
