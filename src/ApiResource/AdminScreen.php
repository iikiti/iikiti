<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

/**
 * Value object describing an admin screen contributed by a plugin to the
 * admin UI.
 *
 * A screen describes a route/page in the admin SPA. Screens are discovered
 * at runtime via {@see \iikiti\CMS\Admin\AdminExtensionInterface::getAdminScreens()}
 * and aggregated by {@see \iikiti\CMS\Admin\AdminMenuRegistry}, then served
 * to the SPA as the dynamic route manifest via the `/api/admin/screens` endpoint.
 *
 * Screens come in several flavours:
 * - `list`     — renders a generic `GenericListPage` wired to `apiPath`
 * - `detail`   — renders a generic `GenericDetailPage` wired to `apiPath/{id}`
 * - `form`     — renders a generic `GenericFormPage` for create/edit
 * - `custom`   — either a core component resolved from the SPA's CORE_COMPONENTS
 *                registry (when `component` is set and `bundle` is null), or a
 *                plugin-provided Svelte component loaded on demand via dynamic
 *                `import()` (when `bundle` is set).
 *
 * @see \iikiti\CMS\Admin\AdminExtensionInterface::getAdminScreens()
 */
class AdminScreen
{
	/**
	 * @param array<string, mixed> $config Screen-specific config (columns, fields)
	 */
	public function __construct(
		public string $path,
		public string $title,
		public string $type = 'custom',
		public ?string $apiPath = null,
		public ?string $bundle = null,
		public ?string $component = null,
		public ?string $permission = null,
		public ?string $icon = null,
		public ?string $description = null,
		public array $config = [],
		public ?string $resource = null,
	) {
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public static function create(
		string $path,
		string $title,
		string $type = 'custom',
		?string $apiPath = null,
		?string $bundle = null,
		?string $component = null,
		?string $permission = null,
		array $config = [],
	): self {
		return new self(
			path: $path,
			title: $title,
			type: $type,
			apiPath: $apiPath,
			bundle: $bundle,
			component: $component,
			permission: $permission,
			config: $config,
		);
	}
}
