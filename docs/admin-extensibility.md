# Admin UI Extensibility

## Overview

Plugins can extend the admin UI by implementing the
`AdminExtensionInterface`. Services implementing this interface are
automatically tagged with `iikiti.admin.extension` and collected by the
`AdminMenuRegistry`.

Beyond menu items and API resource descriptors, plugins can now contribute
**admin screens** — full page views that appear in the admin navigation and
are dynamically loaded by the SPA. Screens can be:

1. **Generic** — built from metadata (API path, column/field definitions)
   using core component templates (list, detail, form). No custom JavaScript
   required.
2. **Custom** — a plugin-supplied Svelte component loaded on demand via
   dynamic `import()`, built using the **core admin component library**
   (`@iikiti/admin`).

## Core Component Library

The admin SPA exposes a set of reusable Svelte 5 components under the
`@iikiti/admin` import alias. Both the core CMS and plugins can import these
components to build consistent UI.

### Available Components

| Component | Purpose |
|-----------|---------|
| `AdminLayout` | Sidebar navigation + header + content outlet |
| `Icon` | Lucide icon by kebab-case name (e.g. `users`, `puzzle`) |
| `DataTable` | Paginated, sortable table with configurable columns |
| `PageHeader` | Page title + description + action buttons |
| `LoadingState` | Spinner with optional label |
| `ErrorBoundary` | Error display with retry |
| `EmptyState` | Empty results message |
| `Button` | Button with variants (primary/secondary/danger/ghost/icon) |
| `Badge` | Status badges (success/warning/danger/primary) |
| `Tabs` | Tab navigation |
| `Breadcrumb` | Breadcrumb trail |
| `Pagination` | Page navigation controls |
| `SearchForm` | Search + filter input bar |
| `Dialog` | Modal overlay |
| `DetailView` | Key-value detail display |
| `Card` | Bordered container with optional header/footer |
| `Form` | Form wrapper with field rendering from schema |
| `FormField` | Label + control + error wrapper |
| `TextInput` | Text input |
| `TextareaInput` | Textarea |
| `SelectInput` | Single/multi select |
| `CheckboxInput` | Checkbox with label |
| `ToggleInput` | On/off toggle |

### Importing Core Components

In your plugin's Svelte source:

```ts
import { DataTable, PageHeader, Button } from '@iikiti/admin';
```

Configure your build to resolve `@iikiti/admin` to the core component
barrel export (`assets/svelte/admin/components/index.ts`). See
`webpack.config.mjs` in the core project for the alias configuration:

```js
// webpack.config.mjs (in your plugin)
module.exports = {
    resolve: {
        alias: {
            '@iikiti/admin': '/path/to/core/assets/svelte/admin/components/index.ts',
        },
    },
    // Use webpack externals so your bundle stays small:
    // externals: { '@iikiti/admin': 'iikitiAdmin' }
};
```

## Screen Registry

### Screen Types

| Type | Behavior |
|------|----------|
| `list` | Renders `GenericListPage` — fetches paginated data from `apiPath`, renders `DataTable` with columns from `config` |
| `detail` | Renders `GenericDetailPage` — fetches a single record from `apiPath/{id}`, renders `DetailView` |
| `form` | Renders `GenericFormPage` — renders a `Form` from `config.fields`, POSTs/PUTs to `apiPath` |
| `custom` | Renders a core component (via `component` name with no `bundle`) or a plugin-loaded component (via `bundle` + `component`) |

### Implementing Admin Screens in a Plugin

Add `getAdminScreens()` to your `AdminExtensionInterface` implementation. Use
the `AdminExtensionTrait` if your plugin only contributes menu items (the
trait provides an empty default for `getAdminScreens()`):

```php
<?php

namespace Acme\Plugin\Blog\Admin;

use iikiti\CMS\Admin\AdminExtensionInterface;
use iikiti\CMS\Admin\AdminExtensionTrait;
use iikiti\CMS\ApiResource\AdminScreen;

class BlogAdminExtension implements AdminExtensionInterface
{
	use AdminExtensionTrait;

	public function getMenuItems(): array
	{
		return [
			AdminMenuItem::create('Blog', '/blog', 'edit-3', 200, [
				AdminMenuItem::create('All Posts', '/blog/posts'),
				AdminMenuItem::create('Categories', '/blog/categories'),
			]),
		];
	}

	public function getAdminScreens(): array
	{
		return [
			// Generic list screen — no custom JavaScript needed
			new AdminScreen(
				path: '/blog/posts',
				title: 'Posts',
				type: 'list',
				apiPath: '/admin/blog/posts',
				config: [
					'columns' => [
						['key' => 'id', 'label' => 'ID'],
						['key' => 'title', 'label' => 'Title'],
						['key' => 'status', 'label' => 'Status'],
					],
				],
			),
			// Custom screen — loads a plugin-bundled Svelte component
			new AdminScreen(
				path: '/blog',
				title: 'Blog Dashboard',
				type: 'custom',
				bundle: '/admin-plugins/acme-blog/dist/admin.js',
				component: 'BlogDashboard',
			),
		];
	}

	public function getResources(): array
	{
		return [];
	}
}
```

### Screen Descriptor Fields

| Field | Type | Description |
|-------|------|-------------|
| `path` | string | Hash route path (e.g. `/blog/posts`) |
| `title` | string | Page title for the header |
| `type` | string | `list`, `detail`, `form`, or `custom` |
| `apiPath` | string? | REST endpoint for generic screens |
| `bundle` | string? | JS bundle URL for custom screens |
| `component` | string? | Component name (core registry key or plugin export) |
| `permission` | string? | Permission required (`ROLE_*` or `{objectType}:{action}`) |
| `icon` | string? | Sidebar icon |
| `description` | string? | Page subtitle |
| `config` | array | Columns (list), fields (detail/form), actions |
| `resource` | string? | Resource name from plugin metadata |

### How the SPA Resolves Screens

On startup, the admin SPA:

1. Fetches `/api/admin/menu` for navigation items.
2. Fetches `/api/admin/screens` for the screen manifest (all registered screens).
3. When the user navigates to a path:
   - Finds the matching `AdminScreen` by `path`.
   - If the screen has `bundle` + `component`: dynamically `import()`s the
     plugin's JS bundle and resolves the named component.
   - If the screen has `component` but no `bundle`: resolves from the
     `CORE_COMPONENTS` registry (statically bundled core route components).
   - If the screen has no `bundle` and a `type` of `list`/`detail`/`form`:
     renders the corresponding generic page component.
4. Loaded plugin components are cached for subsequent navigations.

### Plugin UI Bundles

To ship custom Svelte components:

1. Create `plugin.json` with an `admin_ui` entry:

```json
{
    "admin_ui": {
        "entry": "dist/admin.js"
    }
}
```

2. Build your Svelte bundle (using the same Svelte 5 + TypeScript toolchain).
   Your `dist/admin.js` should export named components:

```svelte
<!-- src/BlogDashboard.svelte -->
<script>
    import { PageHeader, Card } from '@iikiti/admin';
</script>

<PageHeader title="Blog Dashboard" description="Overview of your blog." />
<Card>
    <p>Your blog analytics go here.</p>
</Card>
```

3. The `PluginAssetController` serves your bundle at
   `/admin-plugins/{slug}/dist/admin.js` (protected by `ROLE_ADMIN`).

## Menu Item Properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `label` | string | — | Display text in the sidebar |
| `path` | string | — | Hash-based routing path (e.g. `/blog/posts`) |
| `icon` | string? | null | Icon identifier (rendered as emoji) |
| `badge` | string? | null | Optional badge text |
| `children` | list<AdminMenuItem> | [] | Submenu items |
| `priority` | int | 0 | Sort order (higher = first) |

## Menu Ordering

Menu items are sorted by `priority` (descending) within each extension.
Plugins should use priorities to position their sections:

| Range | Purpose |
|-------|---------|
| 1000+ | Core sections (reserved) |
| 500–999 | High-priority plugins |
| 100–499 | Standard plugins |
| 0–99 | Low-priority extensions |
| -100+ | Utility sections |

## Registering as a Service

If your plugin uses auto-registration (standard for Symfony bundles), the
service is auto-tagged with `iikiti.admin.extension` via the
`#[AutoconfigureTag]` attribute on `AdminExtensionInterface`.

Explicit registration (if auto-registration is disabled):

```yaml
services:
    Acme\Plugin\Blog\Admin\BlogAdminExtension:
        tags: ['iikiti.admin.extension']
```
