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

## Workflow graph providers

Plugins can provide versioned DB workflow definitions and register policy,
transition-action, subject-resolver, task-assignment, and MFA-factor services.
These extension points use stable keys rather than PHP class names in graph
JSON. The plugin manifest ID owns its definitions, and the provider's source
version participates in the persisted definition version hash.

```php
use iikiti\CMS\Workflow\Definition\WorkflowDefinitionData;
use iikiti\CMS\Workflow\Definition\WorkflowDefinitionProviderInterface;

final class ApprovalWorkflowProvider implements WorkflowDefinitionProviderInterface
{
    public function getOwnerKey(): string { return 'vendor/approval'; }
    public function getSourceVersion(): string { return '1.2.0'; }

    public function getDefinitions(): iterable
    {
        yield new WorkflowDefinitionData(
            'review_request',
            'Review request',
            [
                'type' => 'workflow',
                'places' => ['draft', 'approved', 'rejected', 'complete'],
                'transitions' => [
                    ['name' => 'approve', 'from' => 'draft', 'to' => 'approved', 'metadata' => ['guardKeys' => ['vendor.approval.can_approve']]],
                    ['name' => 'reject', 'from' => 'draft', 'to' => 'rejected'],
                    ['name' => 'finish_approved', 'from' => 'approved', 'to' => 'complete'],
                    ['name' => 'finish_rejected', 'from' => 'rejected', 'to' => 'complete'],
                ],
                'initialPlaces' => ['draft'],
                'finalPlaces' => ['complete'],
                'metadata' => ['policyKey' => 'vendor.approval.policy'],
            ],
            'vendor.approval.policy',
        );
    }
}
```

The provider service is autoconfigured via `WorkflowDefinitionProviderInterface`.
After a plugin install/update and container rebuild, synchronize active plugin
sources into immutable DB versions with:

```sh
php bin/console iikiti:workflow:sync-definitions
```

Global admins may assign plugin definitions to sites. Site managers may use an
assigned plugin definition or fork it into a site-owned editable version; they
cannot mutate the plugin-owned source. Existing instances remain pinned to their
definition version. If a plugin is disabled, its active instances pause with a
visible reason and resume after activation once the pinned handler/policy keys
are available.

`WorkflowPolicyInterface` is the required authorization/guard hook; missing
policy keys fail closed. `WorkflowTransitionActionInterface` handles trusted
code effects, and `WorkflowSubjectResolverInterface` maps a registered subject
type key plus opaque ID to plugin domain data. Human-task assignment may use a
registered `WorkflowTaskAssignmentResolverInterface` or a direct user/site role.
Action handlers execute inside the workflow transition transaction: queue
external I/O through Messenger rather than performing non-transactional network
side effects inline.

MFA factor plugins implement `MfaFactorProviderInterface`, including
`isAvailableFor()`, a provider version, and a unique proof key. Admin MFA graphs
may reference registered factor keys, but the core proof policy verifies the
current required proof set before authentication; plugin graphs cannot set
proof flags directly or override `core.mfa_proof_gate`.

Never put service FQCNs, PHP expressions, executable code, or raw SQL in graph
documents. Use bounded graph metadata and registered stable keys only; update
the source version when plugin behavior changes so sessions and instances can
detect the new definition version.

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
For every `window.iikiti` member and readiness signalling (`whenReady`), see
[js-api.md](js-api.md).

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

// 3. Register an ordered group in a tab, then place settings in it.
sb.registerGroup('seo', { id: 'social', label: 'Social metadata', order: 20 });
sb.registerSection({
  id: 'seo', label: 'SEO', order: 40,
  build: (ctx) => [{
    kind: 'field', path: 'content',
    field: { key: 'og_title', label: 'Open Graph title', type: 'text', group: 'social', order: 10 },
  }],
});

// 4. Modify an existing tab: insert/reorder/remove nodes.
sb.patchSection('style', (nodes) => [...nodes, myNode], { priority: 0 });

// 5. Switch the active tab programmatically (e.g. from a tour).
sb.setActiveSection('element');

// 6. Inspect registered groups in their display order.
const groups = sb.getGroups('seo');
console.log(groups);
```

Field write paths map to the block node: `content → node.content`, `element →
node.element`, `style → node.style.<active-breakpoint>` in the Style tab (other
tabs use `base`). Core and plugin style fields can specify `group` and numeric
`order`; settings sort ascending inside
their accordion, with declaration order breaking ties. An unregistered group id
falls back to General until the plugin registers it.

```php
new BlockType(
    type: 'acme-card',
    label: 'Card',
    category: 'layout',
    styleFields: [
        ['key' => 'backgroundColor', 'label' => 'Card background', 'type' => 'color', 'group' => 'appearance', 'order' => 20],
    ],
);
```

The Style tab provides base and configured responsive breakpoints; values not
specified at a breakpoint inherit from the nearest smaller breakpoint. Clear a
specific responsive value to remove its override. To write elsewhere, give a
sidebar node a `get(ctx)`/`set(ctx, value)` pair instead of `path`.

Registered groups are section-scoped. `order` determines accordion placement;
field `order` determines ordering within it:

```js
sb.registerGroup('style', { id: 'appearance', label: 'Appearance', order: 55 });
const styleGroups = sb.getGroups('style');
```

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

## Distributed batches and schedules

The core batch engine runs messages through Symfony Messenger, persists item
state and dependencies, and delivers at least once. Handlers must therefore be
idempotent. Messages are JSON envelopes with stable type keys and payloads no
larger than 64 KiB; keep image/video bytes in protected storage and put only an
asset ID or API URI in the payload. The framework defines the common `text`,
`image`, and `video` type keys but no default processing behavior. Register a
handler for each type the plugin submits.

```php
use iikiti\CMS\Batch\BatchMessage;
use iikiti\CMS\Batch\BatchMessageContext;
use iikiti\CMS\Batch\BatchMessageHandlerInterface;
use iikiti\CMS\Batch\BatchMessageType;

final class VideoTranscodeHandler implements BatchMessageHandlerInterface
{
    public function getType(): string
    {
        return BatchMessageType::VIDEO;
    }

    public function handle(BatchMessage $message, BatchMessageContext $context): void
    {
        $context->report('Transcoding video', ['itemKey' => $message->key]);
        // Resolve the protected video by ID and perform idempotent work.
    }
}
```

Implementing `BatchMessageHandlerInterface` registers the service automatically.
Use `BatchManager::submit()` for one-off work. Each message has an item key,
stable idempotency key, version and optional `dependsOn` item keys. Dependencies
must be an acyclic graph. Ready items run independently; after the configurable
maximum attempts (default 3) are exhausted, dependents are marked skipped while
unrelated branches continue. Throw `RetryableBatchMessageFailure` for a retry,
`PermanentBatchMessageFailure` for terminal failure, or `BatchMessageSkipped`
for a reviewable skip. `BatchMessageContext::report()` publishes safe activity
summaries without exposing payloads.

To make a task schedulable, implement `ScheduledTaskProviderInterface` and
provide a stable key, `site`/`global` scope, validated options schema and
`createMessages()` factory, plus `getBatchConfiguration()` for safe job metadata.
The Schedules admin screen only allows registered
providers; it does not accept arbitrary code or message payloads. Global tasks
require a super/system administrator. A provider may opt into overlapping runs
only if its work is explicitly safe.

Symfony Scheduler is the default backend (`SCHEDULER_ADAPTER=symfony`). An
alternative backend can implement `SchedulerAdapterInterface` and invoke the
shared `BatchScheduleRunner`; select it with `SCHEDULER_ADAPTER=<registered-key>`.
Only Symfony Scheduler is shipped as a backend. Alternative adapters must use
the shared schedule occurrence claims to avoid duplicate dispatch. Consume
`batch_processing` and `scheduler_default` with separate Messenger workers.

The admin library exports `BatchProgress` from the `@iikiti/ui` alias. Plugins
can embed it in custom screens with the shared API client and a batch ID. Its
single event endpoint supports polling (`mode=poll`) and SSE (`mode=stream`);
the SSE reader uses authenticated `fetch` so API tokens stay in headers.
