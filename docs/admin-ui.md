# Administration UI

## Overview

The iikiti CMS administration UI is a separate Single Page Application (SPA) built
with Svelte 5 (runes mode). It provides list views and metadata editing for
users, user groups, roles, applications, sites, site groups, and audit logs.

Full content editing (pages, blocks, etc.) is handled on the front-end and is
**out of scope** for the admin UI.

## Accessing the Admin

1. Navigate to `/admin` in your browser.
2. If not already authenticated, you'll be redirected to the login page.
3. Complete two-factor authentication if enabled.
4. After authentication, an ephemeral API token is generated and injected into
   the page. The SPA uses this token (via the `X-AUTH-TOKEN` header) to make
   authenticated calls to `/api/admin/*`.

## Navigation

The admin sidebar navigation is dynamically built from the
`/api/admin/menu` endpoint, which aggregates menu items from the core admin
extension and any plugin extensions implementing
`iikiti\CMS\Admin\AdminExtensionInterface`.

Default menu structure:

```
Dashboard
Users
User Groups
Roles & Permissions
Applications
Sites
Site Groups
Plugins
  ├── Installed
  └── Store
Search
  ├── Indexes
  ├── Filters
  └── Site Groups
Audit Log
```

## API Endpoints

All admin API endpoints are under `/api/admin/` and require `ROLE_ADMIN`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/users` | List users (paginated) |
| GET | `/api/admin/users/{id}` | Get user details |
| GET | `/api/admin/user-groups` | List user groups |
| POST | `/api/admin/user-groups` | Create user group |
| PUT | `/api/admin/user-groups/{id}` | Update user group |
| DELETE | `/api/admin/user-groups/{id}` | Delete user group |
| GET | `/api/admin/roles` | List all roles |
| GET | `/api/admin/roles/{id}` | Get role details |
| PUT | `/api/admin/roles/{id}` | Update custom permissions |
| DELETE | `/api/admin/roles/{id}` | Delete non-system role |
| GET | `/api/admin/applications` | List applications |
| GET | `/api/admin/sites` | List sites |
| GET | `/api/admin/site-groups` | List site groups |
| GET | `/api/admin/audit-logs` | List audit log entries |
| GET | `/api/admin/menu` | Get admin navigation menu |
| GET | `/api/admin/screens` | Get admin screen manifest (dynamic routing table) |

## Routing Model

The admin SPA uses **hash-based dynamic routing**. On startup it fetches two
endpoints:

1. `/api/admin/menu` — sidebar navigation (menu items).
2. `/api/admin/screens` — screen manifest (route → component mapping).

The SPA builds its route table from the screen manifest. Screens fall into
three categories:

- **Generic screens** (`type: list|detail|form`) — rendered by core
  `GenericListPage`, `GenericDetailPage`, and `GenericFormPage` components
  driven by the screen's `apiPath` and `config` (column/field definitions).
- **Core custom screens** (`type: custom`, `component` set, no `bundle`) —
  resolved from the `CORE_COMPONENTS` registry (statically bundled).
- **Plugin custom screens** (`type: custom`, `bundle` + `component` set) —
  the plugin's JS bundle is dynamically `import()`ed and the named component
  is cached for reuse.

See [admin-extensibility.md](admin-extensibility.md) for the full component
library reference and plugin screen development workflow.

## Core Component Library

The admin UI ships with a library of reusable Svelte 5 components exported
under the `@iikiti/admin` import alias. These include `AdminLayout`, `Icon`,
`DataTable`, `PageHeader`, `Button`, `Badge`, `Dialog`, `Tabs`, `Breadcrumb`,
`Pagination`, `SearchForm`, `Form`, `DetailView`, `Card`, and input components
(`TextInput`, `TextareaInput`, `SelectInput`, `CheckboxInput`, `ToggleInput`).
Plugins can import these components in their own UI bundles.

### Icons

`Icon` renders Lucide icons by kebab-case name and is the standard way to
display icons in admin screens (menu items already emit Lucide names from the
backend):

```svelte
<script>
  import { Icon } from '@iikiti/admin';
</script>

<Icon name="users" size={18} />
```

Unknown names fall back to the `search` icon, so a missing mapping never
breaks rendering.

## Visual Theme

The admin uses the **2027 color palette** with light and dark variants. All
colors are CSS custom properties defined in `assets/styles/admin.css`; they
flip automatically under the `.dark` class on `<html>`.

| Token | Light | Dark | Palette source |
|---|---|---|---|
| `--primary` | `#998B7F` | `#B0A398` | Common Ground (2027 neutral) |
| `--accent` | `#A6613C` | `#C97B52` | Deep Rooted (CTAs, active nav) |
| `--info` | `#7A8F97` | `#9BB7C3` | Cottage Door |
| `--sidebar` | `#5E5C50` | `#2C2B27` | Grounded (dark anchor) |
| `--bg` / `--surface` | `#EFEBE3` / `#F9F7F2` | `#21201E` / `#2C2B27` | warm neutrals |
| `--success` / `--warning` / `--danger` | universal red/amber/green | | status semantics only |

Utility classes (`bg-surface`, `text-accent`, `border-border`, …) are
generated from these tokens by Tailwind CSS v4 (`@theme inline`), so a single
class works in both themes. Reusable `admin-*` component classes
(`.admin-btn`, `.admin-nav-item`, `.admin-badge`, `.admin-card`, `.admin-input`,
`.admin-table`, `.admin-tab`, `.iikiti-popover`, `.iikiti-toast*`) live in
`assets/styles/admin.css` under `@layer components`.

### Theme Toggle (dark mode)

The header includes a sun/moon toggle. Selection is stored in
`localStorage('theme')` and defaults to the OS `prefers-color-scheme`.
An inline script in `templates/admin/layout.twig` applies the stored theme
before the stylesheet loads, so there is no flash of the wrong theme.
Plugins should never hard-code light-only colors — use the semantic tokens
above (they flip for free).

### Shared Layout Components (front-end framework)

Banner/header/footer/sidebar components live in the iikiti front-end
framework (`assets/js/iikiti/components/`) and work on **both** the public
front-end and the admin, because `iikiti.js` is loaded on every page.

```html
<!-- Declarative (public front-end, auto-wired on domReady) -->
<header data-component="header" data-sticky="scroll" data-sticky-toggle="#menu">…</header>
<aside data-component="sidebar" data-sticky="scroll,edge,button"
       data-sticky-toggle="#sidebar-toggle">…</aside>
```

```js
// Imperative (admin SPA, plugin bundles)
window.iikiti.components.create('sidebar', el, {
  sticky: ['scroll', 'edge', 'button'],
  toggleSelector: '#sidebar-toggle',
});
window.iikiti.components.on('banner:update', fn);   // pub/sub for dynamic content
```

Positioning modes: `data-position="static|sticky|absolute"`.
Sticky triggers (composable): `scroll` (hide on down-scroll), `edge`
(reveal near the viewport edge), `hover-target` (`data-sticky-target`),
`button` (`data-sticky-toggle`). All panels accept `setContent()` /
`refresh()` (`data-iikiti-source`) for dynamic content updates.

## List Views

Each list view provides:
- Pagination (Hydra standard)
- Search/filter via query parameters
- Click-to-navigate to detail views

## Metadata Editing

The admin UI supports editing of metadata (names, labels, descriptions,
configurations). Full content editing (pages, blocks, content) is handled on
the front-end.
