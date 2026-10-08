# Plugin Development

iikiti plugins are full Symfony bundles that are discovered, validated and
registered at compile time by the kernel. They are installed under
`cms/extensions/` and served from the iikiti plugin store.

> **Terminology:** "plugin" is the primary term. "Extension" is a legacy alias
> retained for backward compatibility (for example `getEnabledExtensions()`), and
> is accepted by search/discovery.

## Directory layout

```
cms/extensions/
├── active/          # symlinks to installed plugins that are enabled (gitignored)
├── installed/       # extracted plugin packages (gitignored)
│   └── my-blog/
│       ├── plugin.json          # manifest (required)
│       ├── .iikiti-install.json # install metadata written by the CMS
│       ├── src/
│       │   ├── MyBlogBundle.php
│       │   └── Controller/
│       └── config/
│           ├── services.yaml     # optional service definitions
│           └── routes.yaml       # optional routes
└── cache/           # downloaded packages awaiting installation (gitignored)
```

Activation uses a symlink: `active/my-blog -> ../installed/my-blog`. The CMS
creates and removes this automatically.

## Namespace standard

| Plugin origin | Composer name | PHP namespace | Bundle class |
|---------------|---------------|---------------|--------------|
| iikiti | `iikiti/plugin-{slug}` | `iikiti\Extension\{Slug}` | `iikiti\Extension\{Slug}\{Slug}Bundle` |
| third-party | `{vendor}/plugin-{slug}` | `{Vendor}\Plugin\{Slug}` | `{Vendor}\Plugin\{Slug}\{Slug}Bundle` |

The `iikiti\` vendor prefix is **reserved** for plugins built exclusively by
iikiti. The CMS rejects third-party plugins that declare an `iikiti\` namespace;
only packages served and signed by the iikiti store may use it.

Classes live under `src/` (PSR-4). The declared namespace maps to the plugin's
`src/` directory.

## Manifest (`plugin.json`)

```json
{
  "name": "My Blog",
  "slug": "my-blog",
  "namespace": "Acme\\Plugin\\MyBlog",
  "bundle_class": "Acme\\Plugin\\MyBlog\\MyBlogBundle",
  "version": "1.0.0",
  "iikiti_version": "^1.0",
  "description": "A blog plugin for iikiti CMS.",
  "authors": [{ "name": "Jane Doe", "email": "jane@example.com" }],
  "license": "MIT",
  "edition": "standard",
  "dependencies": {
    "acme-core": "^2.0"
  },
  "capabilities": {
    "services": true,
    "routes": true,
    "doctrine_mappings": false
  },
  "permissions": {
    "manage": { "description": "Manage blog posts", "default_roles": ["ROLE_ADMIN"] }
  }
}
```

Required fields: `name`, `slug`, `namespace`, `bundle_class`, `version`,
`iikiti_version`. `slug` must be lowercase alphanumeric with dashes.
`edition` is `standard` or `pro` (used for open-core editions). Declared
`dependencies` (slug → semver constraint) are validated before install/update.

## Bundle class

Every plugin bundle must extend `iikiti\CMS\Plugin\PluginBundle`:

```php
<?php

namespace Acme\Plugin\MyBlog;

use iikiti\CMS\Plugin\PluginBundle;

class MyBlogBundle extends PluginBundle
{
}
```

By default services are loaded from `config/services.{php,yaml}` and routes from
`config/routes.{php,yaml}` or from `src/Controller/*` attribute routes. Override
`loadExtension()` only for custom wiring.

## Lifecycle hooks

Implement `iikiti\CMS\Plugin\Lifecycle\PluginLifecycleInterface` on the bundle to
react to lifecycle transitions. Hooks run once per site for batch (all-sites)
operations and receive a `PluginContext` describing the plugin, site, source and
state.

```php
public function onPluginInstall(PluginContext $context): void;
public function onPluginActivate(PluginContext $context): void;
public function onPluginDeactivate(PluginContext $context): void;
public function onPluginUpdate(PluginContext $context, string $fromVersion): void;
public function onPluginUninstall(PluginContext $context): void;
```

Symfony events are also dispatched (`iikiti.plugin.install`, `activate`,
`deactivate`, `update`, `uninstall`) so plugins can subscribe without
implementing the interface.

## Per-site configuration

Plugins are enabled per site. Their settings are stored in the site's
configuration under `plugins.configuration.{slug}` and read via:

```php
$site->getConfiguration()->getPluginConfiguration('my-blog');
```

Admins can manage values with `iikiti:plugin:configure`.

## Development workflow

1. Place the plugin under `cms/extensions/installed/{slug}/` with a valid
   `plugin.json`.
2. Enable it: `php bin/console iikiti:plugin:enable {slug}` (or create the
   symlink manually).
3. Rebuild the container: `php bin/console cache:clear`.
4. Verify: `php bin/console iikiti:plugin:verify {slug}`.

Install/upgrade commands and the admin API are documented in
[plugin-api.md](plugin-api.md); the trust model is documented in
[plugin-security.md](plugin-security.md).

## Contributing to the Admin UI

Plugins can add menu items, API resources, and **admin screens** to the
administration UI by implementing `iikiti\CMS\Admin\AdminExtensionInterface`.
See [admin-extensibility.md](admin-extensibility.md) for full details.

```php
class AcmeAdminExtension implements AdminExtensionInterface
{
    use iikiti\CMS\Admin\AdminExtensionTrait;

    public function getMenuItems(): array
    {
        return [
            AdminMenuItem::create('Acme', '/acme', 'package', 100),
        ];
    }

    public function getAdminScreens(): array
    {
        return [
            // Generic list screen (no custom JS required)
            new AdminScreen(
                path: '/acme',
                title: 'Acme Content',
                type: 'list',
                apiPath: '/admin/acme',
                config: [
                    'columns' => [
                        ['key' => 'id', 'label' => 'ID'],
                        ['key' => 'name', 'label' => 'Name'],
                    ],
                ],
            ),
        ];
    }

    public function getResources(): array
    {
        return [
            AdminApiResource::create('acme', 'api_acme_get_collection', 'Acme Content'),
        ];
    }
}
```

The service is auto-tagged with `iikiti.admin.extension` when auto-registration
is enabled. The admin SPA will display the menu item and render the screen
automatically.

### Custom Screens with Plugin UI Bundles

For complex workflows requiring custom Svelte components, plugins can ship a
compiled JS bundle. Add `admin_ui` to `plugin.json`:

```json
{
    "admin_ui": {
        "entry": "dist/admin.js"
    }
}
```

The bundle is served at `/admin-plugins/{slug}/dist/admin.js` and is loaded on
demand via dynamic `import()` when the user navigates to the screen's path.
Plugins can import core components from `@iikiti/ui`:

```svelte
<script>
    import { PageHeader, DataTable } from '@iikiti/ui';
</script>

<PageHeader title="Acme Dashboard" />
```

Register the custom screen in `getAdminScreens()`:

```php
new AdminScreen(
    path: '/acme/dashboard',
    title: 'Acme Dashboard',
    type: 'custom',
    bundle: '/admin-plugins/acme-blog/dist/admin.js',
    component: 'AcmeDashboard',
),
```

## Site UI (public front-end chrome)

Plugins that add public-facing chrome (headers, pencil banners, sidebars,
dock strips, floating tools) must use the front-end UI standard so their
bars stack with the editor toolbar and any theme chrome without covering
anything:

- **Viewport bars:** declarative `data-iikiti-bar` markup or the imperative
  `iikiti.bars.register()` API (available on every page via
  `window.iikiti`), including the explicit `fixed`/`absolute` overlay
  exemption and optional drag-to-resize.
- **Layering:** the shared z-index token scale
  (`--iikiti-z-*`: content, editor, shell, chrome, popover, floating, modal,
  tour, tooltip, toast; see the Layer scale table in
  `docs/front-end-ui-standard.md`). Read values in JS with
  `assets/js/iikiti/chrome/layers.js`.
- **Floating draggable dialogs & tooltips:** `FloatingPanel` /
  `Tooltip` components (import from `@iikiti/ui`), or the framework-level
  `dragResize` helper for vanilla-JS bundles.

Full reference: [front-end-ui-standard.md](front-end-ui-standard.md).

## Editor sidebar extension API

Plugins can extend the block editor settings sidebar (Content / Element / Style)
through the same API the core uses — exposed at runtime as
`window.iikiti.editor.sidebar` (available once the editor loads).

```js
const sb = window.iikiti.editor.sidebar;

// 1. Add a new field-control component (a Svelte component with
//    `{ field, value, onChange }` props) for a custom field type.
sb.registerFieldControl('my-widget', MyWidgetControl, { priority: 10 });

// 2. Add a new tab. `build(ctx)` returns a list of nodes:
//    {kind:'field', field, path} | {kind:'group', id,label,header?, nodes} |
//    {kind:'repeater', id, fields, items, onChange, addLabel}
sb.registerSection({
  id: 'seo', label: 'SEO', icon: 'search', order: 40,
  build: (ctx) => [
    { kind:'field', field:{key:'meta_title', label:'Meta title', type:'text'}, path:'content' },
    { kind:'group', id: 'open-graph', label:'Open Graph', nodes:[/*...*/] },
  ],
});

// 3. Modify an existing tab: insert/reorder/remove nodes.
sb.patchSection('style', (nodes) => [...nodes, myNode], { priority: 0 });

// 4. Switch the active tab programmatically (e.g. from a tour).
sb.setActiveSection('element');
```

Field write paths map to the block node: `content → node.content`, `element →
node.element`, `style → node.style.base`. To write elsewhere, give a node a
`get(ctx)`/`set(ctx, value)` pair instead of `path`.

A reusable **group + repeater** pattern (the built-in "Attributes" group is a
concrete example):

```js
{ kind:'group', id:'attributes', label:'Attributes', header:'Attributes',
  nodes:[
    { kind:'repeater', id:'attributes',
      fields:[{key:'name',type:'text'},{key:'value',type:'text'}],
      items: ctx.node.element?.attributes ?? [],
      addLabel:'Add attribute',
      onChange: (items) => ctx.update({ element:{ ...ctx.node.element, attributes: items } }),
    },
  ] }
```

Editor UI bundles ship via manifest `editor_ui.entry` (served from
`/admin-plugins/{slug}/...`) and are loaded by the editor on mount before
`tour`-style steps can target them.
