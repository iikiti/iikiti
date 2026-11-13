# Plugin Development

iikiti plugins are full Symfony bundles that are discovered, validated and
registered at compile time by the kernel. First-party plugins shipped with the
application live under `plugins/`; store/developer-installed plugins use
`cms/extensions/`.

A theme is not a separate package type or runtime subsystem. It is presentation
functionality inside a plugin bundle. A plugin may provide only a theme, only
application code, or both; all of them use the same manifest, bundle, services,
and Fragment API conventions.

> **Terminology:** "plugin" is the primary term. "Extension" is a legacy alias
> retained for backward compatibility (for example `getEnabledExtensions()`), and
> is accepted by search/discovery.

## Directory layout

```
plugins/
└── forma-2026/                   # built-in theme-only plugin
    ├── plugin.json
    ├── src/Forma2026Bundle.php
    ├── src/Fragment/ThemeStylesFragment.php
    ├── config/services.yaml
    ├── assets/theme.css
    └── templates/
        ├── default-block-editor-content.twig
        └── errors/page.html.twig

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

First-party bundles shipped with the application live in the repository's
`plugins/{slug}/` directory. The kernel validates and loads these built-in
plugins alongside active store/developer-installed plugins. Built-ins are
trusted application code, active for all sites, and are not installed or removed
through the plugin store lifecycle.

## Namespace standard

| Plugin origin | Composer name | PHP namespace | Bundle class |
|---------------|---------------|---------------|--------------|
| iikiti | `iikiti/plugin-{slug}` or an application-bundled package | `iikiti\Extension\{Slug}` | `iikiti\Extension\{Slug}\{Slug}Bundle` |
| third-party | `{vendor}/plugin-{slug}` | `{Vendor}\Plugin\{Slug}` | `{Vendor}\Plugin\{Slug}\{Slug}Bundle` |

The `iikiti\` vendor prefix is **reserved** for plugins built exclusively by
iikiti. The CMS rejects third-party plugins that declare an `iikiti\` namespace;
only application-bundled plugins and packages served and signed by the iikiti
store may use it.

Classes live under `src/` (PSR-4). The declared namespace maps to the plugin's
`src/` directory.

## MCP API integration

All caller-facing plugin routes under `/api/` are included in the shared iikiti
MCP API catalog. API Platform operations use their resource metadata and schemas;
custom controller routes use their route and controller metadata. The model
discovers these entries through `iikiti.api_list`, gets full input/output details
from `iikiti.api_describe`, and calls them through `iikiti.api_request`.

There is exactly one MCP server endpoint (`/api/mcp`). A plugin must never
configure a separate MCP endpoint, server, or credential. Plugin routes are
namespaced from the owning plugin manifest and stay subject to the same API
authentication, authorization, site context, and existing endpoint validation.
Authentication and internal worker routes are not listed as callable APIs.

For callable business functions that do not have an HTTP API route, implement
`iikiti\CMS\Mcp\McpApiToolInterface` and register the service in the plugin's
normal `config/services.yaml` with autoconfiguration enabled; otherwise add the
`iikiti.mcp_api_tool` service tag explicitly. Its stable local name is prefixed
by the runtime with `iikiti.plugin.<plugin-slug>.`; descriptions
must explain purpose, appropriate use, side effects, and required context. Every
input property must have a description and a valid JSON Schema. The existing
business API/service used by `execute()` must perform its normal authorization,
validation, site checks, and audit logging.

```php
namespace Acme\Plugin\Blog\Mcp;

use iikiti\CMS\Mcp\McpApiToolInterface;
use Acme\Plugin\Blog\Service\PostApi;

final class ReadPostsTool implements McpApiToolInterface
{
    public function __construct(private PostApi $posts) {}

    public function getName(): string { return 'read_posts'; }
    public function getTitle(): string { return 'Read blog posts'; }
    public function getDescription(): string
    {
        return 'Find blog posts visible to the current user and site. Use this to search or inspect posts; it does not modify content.';
    }
    public function getRequiredPermission(): string { return 'ROLE_ADMIN'; }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Optional title/content search text.'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum posts to return.', 'minimum' => 1, 'maximum' => 50],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getOutputSchema(): ?array { return null; }
    public function getAnnotations(): array { return ['readOnlyHint' => true, 'openWorldHint' => false]; }

    public function execute(array $arguments): mixed
    {
        return $this->posts->search($arguments['query'] ?? '', $arguments['limit'] ?? 20);
    }
}
```

The example is a read-only API; implement separate write functions where the
feature supports them, and describe their effects accurately. Implementations
must not add `#[McpTool]`: the only MCP-level tools are the three iikiti wrappers.
See [MCP API](mcp-api.md) for the complete endpoint contract and deployment
configuration.

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
  "actions": {
    "claims": ["security.firewall.inspect.sqli"],
    "conflicts": ["content.transform.minify.html"]
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

### Declaring and discovering plugin actions

Use `actions.claims` to declare stable semantic behaviors that another plugin or
an administrator may need to discover. IDs are exact, lowercase, namespaced
keys up to 128 characters, such as `security.firewall.inspect.sqli`,
`security.firewall.inspect.xss`, or `content.transform.minify.html`. Choose
shared behavior names rather than PHP classes or plugin-specific IDs when two
plugins may perform the same kind of work.

`actions.conflicts` lists claim IDs that cannot safely be active alongside this
plugin. For example, a formatter that reverses HTML minification can declare a
conflict with `content.transform.minify.html`. A plugin that must be the only
one performing a claimed action can list its own claim ID as a conflict. Claims
with the same ID are discoverable but do not block activation by themselves;
conflicts are explicit and matched by exact ID in either direction. Declarations
are coordination metadata and do not prove what a plugin's code actually does.

Plugins can inject `iikiti\CMS\Plugin\PluginActionRegistry` to inspect this
metadata without loading another plugin's code:

```php
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Plugin\PluginActionRegistry;

final class ActionAwareService
{
    public function __construct(private PluginActionRegistry $pluginActions) {}

    public function check(Site $site): void
    {
        // Omitting the site searches every installed manifest; passing it limits
        // the result to plugins enabled on that site.
        $firewallPlugins = $this->pluginActions->findClaimants('security.firewall.inspect.sqli', $site);
        $conflicts = $this->pluginActions->findConflicts('my-plugin', $site);
    }
}
```

`getInstalledPlugins()` returns all installed manifests, including inactive
plugins. `getActivePluginsForSite($site)` returns only plugins enabled on that
site. `findClaimants($actionId)` uses exact IDs and searches installed manifests;
pass a `Site` to restrict it to active plugins. `findConflicts($pluginSlug,
$site)` checks the candidate plugin against the plugins currently active there.
Use these APIs to coordinate behavior at runtime; the CMS independently blocks
declared hard conflicts during activation unless an administrator explicitly
overrides them.

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

## Background and scheduled work

Plugins can route asynchronous Messenger messages to a configured receiver and
provide scheduled batch work through `ScheduledTaskProviderInterface` (tagged
automatically). The CMS's bounded maintenance runner consumes configured async
receivers, including plugin receivers, after eligible web responses and through
`php bin/console iikiti:maintenance:run`. The `failed` receiver is never consumed
by this fallback. Operators can exclude a plugin receiver by adding its name to
the comma-separated `BACKGROUND_RUNNER_EXCLUDED_RECEIVERS` setting; excluded
queues require a dedicated Messenger worker.

Plugins may register bounded firewall condition services and signed rule-pack
sources; the core refresh task schedules registered packs through this same
batch/scheduler path. See [security-firewall.md](security-firewall.md) for the
condition contract, `iikiti.security` event, pack signature, supported SecLang
subset, site policy API, and the optional conservative rule-index extension.

Lazy execution is best-effort and only advances when web traffic arrives. It
sends the response before processing, but still occupies a PHP process. Keep
plugin handlers idempotent, bound their I/O and CPU work, and split large jobs
into small messages. Each lazy tick is limited by
`BACKGROUND_RUNNER_MAX_MESSAGES`, `BACKGROUND_RUNNER_TIME_LIMIT_SECONDS`, and
`BACKGROUND_RUNNER_COOLDOWN_SECONDS`. Missed recurring occurrences are caught
up incrementally. Sites with OS cron or persistent workers should set
`LAZY_QUEUE_ENABLED=0`; the one-shot CLI runner shares the same execution path.

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

## Localized object properties

Plugins can declare the content locales and query capabilities of object
properties by providing `ObjectPropertyDefinition` values from an
`ObjectPropertyDefinitionProviderInterface` service. The interface is tagged
automatically:

```php
use iikiti\CMS\Plugin\ObjectPropertyDefinition;
use iikiti\CMS\Plugin\ObjectPropertyDefinitionProviderInterface;
use iikiti\CMS\Value\LocaleTag;

final class ArticleProperties implements ObjectPropertyDefinitionProviderInterface
{
    public function getObjectPropertyDefinitions(): iterable
    {
        return [
            new ObjectPropertyDefinition(
                'article',
                'title',
                ['en-CA', 'en-GB', 'fr-CA'],
                LocaleTag::fromString('en-CA'),
                searchable: true,
                sortable: true,
            ),
            new ObjectPropertyDefinition(
                'article',
                'body',
                ['en-CA', 'fr-CA'],
                allowMixedLanguage: true,
                searchable: true,
            ),
        ];
    }
}
```

Use `LocalizedValue::fromTranslations()` for separate values by locale and
`LocalizedValue::fromSegments()` for one ordered value with inline language
changes. Segment locale tags are emitted as escaped `<span lang="…">` elements
in block rendering. `defaultLocale` applies after the user's available
translation preference, and the object's setting can be overridden per
instance with `setLocaleSettings()`. Declaring a property searchable or sortable
creates maintained, locale-aware database projections; undeclared properties
remain storable/renderable but are not queryable through indexed property
filters.

After installing/updating a plugin that adds locales or changes indexed field
definitions, run `php bin/console iikiti:locale-index:rebuild`. See
[`localization.md`](localization.md) for value formats, fallback order, locale
configuration, regional date/number formatting, and query operator behavior.

## Secrets API

Core services and plugins can inject `iikiti\CMS\Secrets\SecretsApi` to store
JSON-compatible sensitive values as encrypted object properties. Secret values
are not returned by ordinary property getters or object serialization and are
excluded from template property snapshots, audit values, search property joins,
and site transfer. Use a plugin-prefixed property name to avoid collisions.

```php
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Secrets\SecretsApi;

final class RemoteSync
{
    public function __construct(
        private readonly SecretsApi $secrets,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function saveCredentials(DbObject $object, array $credentials): void
    {
        $this->secrets->set($object, 'acme.remote.credentials', $credentials);
        $this->entityManager->flush();
    }

    public function credentials(DbObject $object): mixed
    {
        return $this->secrets->has($object, 'acme.remote.credentials')
            ? $this->secrets->get($object, 'acme.remote.credentials')
            : null;
    }
}
```

`set()` and `delete()` change the Doctrine unit of work but do not flush; the
caller controls persistence and transaction boundaries. `get()` returns `null`
for a missing value, so use `has()` to distinguish a missing key from a stored
JSON `null`. Values must be JSON-encodable. Secret owners must already be
persisted and managed by the current EntityManager. A `Site` object uses its own
ID for its key scope, ordinary objects use their `site_id`, and `site_id = 0`
uses the global key. Plugins are trusted in-process code, not sandboxed
principals: a plugin that receives an object and key can read that secret. This
service adds no HTTP endpoint. Since encryption scope follows the owner's site,
read and reset secrets through `SecretsApi` before changing an object's site ID;
directly moving an object makes its old secret payloads unreadable.

### Wrapping-key configuration and rotation

Configure a separate runtime keyring; never commit these values. Generate a
random 32-byte wrapping key with `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`
and provide the key ID and JSON keyring through the deployment secret manager:

```dotenv
SECRETS_ACTIVE_KEY_ID=2026-01
SECRETS_KEYRING='{"2026-01":"BASE64_ENCODED_32_BYTE_KEY"}'
```

Each site and the global scope have a random master key wrapped by the active
keyring key. Secret payloads are authenticated-encrypted with the scope master
key. Keep an encrypted, access-controlled backup of every wrapping key: losing
all keys required by stored rows makes those secrets unrecoverable.

To rotate, deploy the new key alongside the old key and set the new ID active:

```dotenv
SECRETS_ACTIVE_KEY_ID=2026-02
SECRETS_KEYRING='{"2026-01":"OLD_BASE64_KEY","2026-02":"NEW_BASE64_KEY"}'
```

Then run `php bin/console iikiti:secrets:rotate-keys` once against the database.
The command transactionally re-wraps the small set of per-scope master keys and
leaves secret property ciphertext unchanged. After it succeeds, verify all
application instances use the new active key, then remove the old key from the
deployed keyring. Rotation requires old keys to remain configured until every
stored master key using them has been re-wrapped.

For purpose-specific application key provisioning and coordinated rotation of
site secrets and transfer credentials, see [Application Keys](application-keys.md).

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
  exemption, `includeInLayout` override, detailed filtered `bars.list()`
  snapshots, per-bar/global change subscriptions, and optional drag-to-resize.
  Framework-controlled header/footer/sidebar hide/show uses accelerated
  flow-collapse by default; custom plugin bars can opt in with
  `collapse: 'flow'` or `data-iikiti-bar-collapse="flow"`. Use the returned
  registration handle's `setVisible()` and `refresh()` when custom JS/CSS
  changes visibility or geometry.
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

## Theme development within a plugin

Themes are a subset of plugin development, not a distinct package type. Use the
regular plugin manifest, bundle class, Twig namespace, services, and Fragment
API. A theme-only plugin may contain no controllers or CMS data entities; a
plugin may also combine a theme with any amount of application functionality.
There is no separate theme registry or theme activation system.

### Built-in default: Forma-2026

The default installation ships **Forma-2026** (`forma-2026`) as a first-party,
theme-only plugin in `plugins/forma-2026/`. Its `Forma2026Bundle` is loaded by
the kernel with the other built-in and active plugin bundles. The theme's Twig
templates use the bundle namespace `@Forma2026/`; `Template::DEFAULT_LAYOUT`
selects `@Forma2026/default-block-editor-content.twig`. The site treats
`forma-2026` as a required plugin, so its static stylesheet fragment is
available for every site without per-site install or enable steps. The bundle
is shipped and updated with the application, not through the store lifecycle.

```text
plugins/forma-2026/
├── plugin.json
├── src/Forma2026Bundle.php
├── src/Fragment/ThemeStylesFragment.php
├── config/services.yaml
├── assets/theme.css
└── templates/
    ├── default-block-editor-content.twig
    └── errors/page.html.twig
```

The generic historic layout reference remains as an alias for saved templates.
New and legacy default layouts use the plugin's templates and styles; custom
layout references are not rewritten. Forma-2026 provides a calm editorial
system, responsive typography/surfaces, light and dark semantic palettes,
accessible focus/skip navigation, and a reusable `.forma-card` / `.forma-meta`
pattern set.

### Providing a theme in an installed plugin

Keep theme layouts and styles inside the plugin that owns them. For a bundle
named `JournalBundle`, Symfony uses `@Journal/` as its Twig namespace:

```text
cms/extensions/installed/acme-journal/
├── plugin.json
├── src/JournalBundle.php
├── src/Fragment/ThemeStylesFragment.php
└── templates/themes/field-notes/layout.twig
```

Select `@Journal/themes/field-notes/layout.twig` as the `Template.layout`. The
plugin is still a normal plugin: store/developer installation and per-site
activation use the usual `cms/extensions/installed/{slug}` package and
`cms/extensions/active/{slug}` symlink. A theme-only plugin needs no controller,
admin page, or database object unless its theme actually requires them.

Every block-editor layout must render the existing `main` region and treat
`header`, `aside`, `footer`, and `dialog` as optional global shells, not extra
content regions. Empty shell roles must produce no markup. Keep one main
landmark and one page-level `h1`; add the skip link before the header's first
interactive item. If a shell uses `data-iikiti-bar`, assume it may be reparented
under `<body>` and do not depend on an ancestor wrapper for its styling. Follow
the viewport-bar and `--iikiti-z-*` guidance earlier in this document.

Example layout (the actual page template may add header/footer shells and theme
partials as needed):

```twig
<div class="acme-journal-theme">
    <a class="acme-journal-skip" href="#acme-journal-main">Skip to content</a>
    <main id="acme-journal-main" tabindex="-1">
        {{ iikiti_region('main', 'main', 'Main content', ['container'], region_main|default('')) }}
    </main>
</div>
{{ iikiti_fragment('acme-journal.theme-styles') }}
```

Use plugin-prefixed CSS classes/custom properties and scope rules to the theme
root where possible. The `site_ui` manifest is not a general stylesheet loader
for server-rendered public pages; use a theme-owned Twig partial or return CSS
through the Fragment API. The Fragment API composes and deduplicates static CSS
with its normal server-rendered page flow:

```php
<?php

namespace Acme\Plugin\Journal\Fragment;

use iikiti\CMS\Web\Fragment\FragmentContext;
use iikiti\CMS\Web\Fragment\FragmentProviderInterface;
use iikiti\CMS\Web\Fragment\FragmentResult;

final class ThemeStylesFragment implements FragmentProviderInterface
{
    public function getName(): string
    {
        return 'acme-journal.theme-styles';
    }

    public function render(FragmentContext $context): FragmentResult
    {
        return new FragmentResult('', [[
            'id' => 'acme-journal.theme-styles',
            'css' => '.acme-journal-theme { --acme-journal-bg: #faf9f5; background: var(--acme-journal-bg); }',
        ]]);
    }
}
```

Keep CSS static/trusted or validate and bound all values used to generate it.
Fragment providers are public and read-only: validate caller-controlled
parameters, rely on Twig autoescaping for HTML, and do not include private or
user-specific data. Retain server delivery by default and the existing
asynchronous page-composition behavior.

### Page patterns, accessibility, and errors

Compose common pages within the single `main` region:

- **Home/content landing:** short introduction followed by linked
  `.forma-card` summaries with descriptive link names.
- **Article/detail:** `<article>`, metadata, one `h1`, a machine-readable
  `<time datetime="…">`, optional captioned figure, and readable prose.
- **Archive/search:** labeled `form role="search"`, result count in text, and a
  list of result links; preserve the query on validation.
- **No results:** keep search available and announce the empty state with
  `role="status"`, not as an error.

Target WCAG 2.2 AA. Verify landmark/heading structure, skip navigation, keyboard
operation and unobscured focus, descriptive labels, error associations, and
status announcements. Measure at least 4.5:1 contrast for normal text and 3:1
for large text and meaningful controls. Check 320 CSS px reflow/400% zoom,
text resizing, reduced motion, forced colors, and dark mode. Prefer 44×44 CSS px
touch targets; the WCAG 2.2 AA minimum is 24×24 CSS px with exceptions.

Forma-2026 includes distinct 404 and 500 pages plus a generic error fallback.
Its pages do not expose exception details and the HTML proxy preserves the HTTP
status and safe `Retry-After`/`Allow` headers. Plugin-owned application errors
should use the same rules and safe public content. Do not assume a plugin's
Twig namespace alone globally replaces Symfony's host-level exception handler.

Automated accessibility tools do not replace keyboard and assistive-technology
checks. See [WCAG 2.2](https://www.w3.org/TR/WCAG22/),
[front-end-ui-standard.md](front-end-ui-standard.md),
[layout-shells.md](layout-shells.md), and
[front-end-editor.md](front-end-editor.md).

## Editor sidebar extension API

Plugins can extend the block editor settings sidebar (Content / Element / Style / Rendering)
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

## Fragment API

Plugins (including themes) can register a named server-rendered fragment and use
the same provider from Twig, the browser API, or a Svelte component. Provider IDs
must be namespaced by the plugin slug, for example `my-blog.promo-card`. A
provider is public and read-only; it is available only on sites where its plugin
is enabled.

Implement `FragmentProviderInterface` and register the service in the plugin
bundle's normal autoconfigured services. The interface auto-tags the provider.
Validate every caller-controlled parameter and keep private/user-specific data
out of public providers:

```php
use iikiti\CMS\Web\Fragment\FragmentContext;
use iikiti\CMS\Web\Fragment\FragmentProviderInterface;
use iikiti\CMS\Web\Fragment\FragmentResult;
use Twig\Environment;

final class PromoCardFragment implements FragmentProviderInterface
{
    public function __construct(private Environment $twig) {}

    public function getName(): string
    {
        return 'my-blog.promo-card';
    }

    public function render(FragmentContext $context): FragmentResult
    {
        $productId = $context->params['productId'] ?? null;
        if (!is_int($productId) || $productId < 1) {
            throw new InvalidArgumentException('A positive productId is required.');
        }

        $html = $this->twig->render('@MyBlog/fragments/promo-card.html.twig', [
            'productId' => $productId,
            'site' => $context->site,
            'locale' => $context->locale,
        ]);

        return new FragmentResult($html, [[
            'id' => 'my-blog.promo-card',
            'css' => '.my-blog-promo-card { display: grid; }',
        ]]);
    }
}
```

`FragmentContext` supplies the current site, locale, and JSON-object `params`;
providers should use Twig autoescaping for untrusted values. Inputs are bounded
to 16 KiB and are visible to the browser/request logs, so never put credentials
or private user data in them. `FragmentResult` accepts trusted HTML up to 1 MiB
and up to 32 CSS fragments with a combined 256 KiB limit. Server-composed plugin
fragments have an 8 MiB combined HTML/CSS budget per page and at most 32 requests
in flight.
The optional CSS `id` should be stable and namespaced; browser loading
deduplicates styles by provider and style ID and also bounds in-flight requests
to 32.

Use the Twig function to render into the initial page by default:

```twig
{{ iikiti_fragment('my-blog.promo-card', {productId: 42}) }}
```

For a dependency-ready set of page fragments, placements can have unique keys
and explicit dependencies. Independent server placements are requested in
parallel by the consumer runtime; dependent placements wait until their listed
sibling placements complete. Dependencies are ordering constraints only; pass
data using validated `params`, never predecessor HTML.

```twig
{{ iikiti_fragment('my-blog.promo-card', {productId: 42}, {key: 'promo'}) }}
{{ iikiti_fragment('my-blog.recommendations', {productId: 42}, {
  key: 'recommendations', dependsOn: ['promo']
}) }}
```

Opt one placement into progressive browser loading with `delivery: 'browser'`;
the Twig function emits an escaped descriptor placeholder and the page loads its
HTML after the shell is returned. A server-delivery placement cannot depend on a
browser-delivery placement. A layout may mix both modes.

```twig
{{ iikiti_fragment('my-blog.promo-card', {productId: 42}, {
  delivery: 'browser', key: 'promo'
}) }}
```

For imperative loading, call the same-origin API from `window.iikiti`. The
implementation is a lazy-loaded JavaScript chunk: unused pages do not download
it, and the first API call or explicit browser placeholder loads it once.

```js
const result = await window.iikiti.fragments.load('my-blog.promo-card', {
  params: { productId: 42 },
  target: document.querySelector('#promo'),
});
console.log(result.html);
```

`load(provider, { params, target, signal })` returns `{html, styles}` and only
replaces the target after a successful response. `mount(root)` loads explicit
Twig browser-delivery descriptors and respects their dependencies. No
environment variable, API-origin setting, or browser credential is required to
use the JavaScript API. For Svelte bundles, import the reusable component from
`@iikiti/ui`:

```svelte
<script>
  import { Fragment } from '@iikiti/ui';
</script>

<Fragment provider="my-blog.promo-card" params={{ productId: 42 }} />
```

The consumer/API role split is a server deployment detail, not a plugin setting.
Browser-facing deployments use the database-free consumer pool to fan out
server-delivery fragments to a separate API pool, then compose a complete HTML
document. Direct API-role page rendering remains a local synchronous fallback.
See [fiber-request-composition.md](fiber-request-composition.md) for worker-pool,
authorization, dependency, and failure behavior.

### Async widget rendering and output cache

The core **Rendering** section stores async and cache policies directly on each
block node. Programmatic block-tree producers can use the same fields:

```php
$widget['renderMode'] = 'always'; // default | always | never
$widget['cacheMode'] = 'user_group'; // none | global | user_group
$widget['cacheGroupId'] = '27'; // required for user_group
```

`default` makes a direct region/shell child its own API fragment and leaves
deeper descendants inline. `always` opts a descendant into an independent
fragment; `never` keeps that node inline. Cache policy is active only for an
independent fragment and defaults to `none`. `global` shares within the current
site/render context. `user_group` shares only among current members of the
selected site/global `UserGroup`; nonmembers render normally but bypass that
entry. These are cache-sharing scopes, not visibility controls. Shared policies
are an assertion that the widget output is identical across the selected
audience; do not use them for per-user output. Query blocks and their repeated
row children remain atomic.

Rendered output uses a system-configurable `CACHE_RENDERED_FRAGMENT_TTL`
(default 300 seconds). CMS object/property writes invalidate rendered fragments;
remote or other undeclared dependencies rely on TTL. A Twig block template can
declare a dependency tag so the fragment can be invalidated when plugin data
changes:

```twig
{{ render_cache.add('catalog_posts') }}
```

Inject `iikiti\CMS\Web\BlockEditor\Render\RenderedFragmentCache` in the
plugin service that changes that data and call
`invalidateTags(['catalog_posts'])`. Tags must use letters, digits, `_`, `-`, or
`.`. Cache failures fall back to rendering normally. The rendered-fragment cache
is separate from Doctrine query-result and oEmbed caches, and public API/HTML
responses remain `private, no-store`. See
[fiber-request-composition.md](fiber-request-composition.md) for the full
composition, invalidation, and security model.

Plugins can also extend the contextual Page/Template Settings sidebar without
adding tabs to a selected block's Content / Element / Style settings. Its
default tabs are General and Advanced; settings sections can target Pages,
Templates or both. Field controls registered with `registerFieldControl()` are
shared by both sidebar modes. The settings build context's `update(key, value)`
updates only that key and preserves other plugin/admin-defined values.

```js
const sidebar = window.iikiti.editor.sidebar;
sidebar.registerSettingsSection({
  id: 'seo', label: 'SEO', icon: 'search', order: 30,
  targetTypes: ['page'],
  build: ({ values }) => [{
    kind: 'field', path: 'settings',
    field: { key: 'meta_title', label: 'Meta title', type: 'text' },
  }],
});

sidebar.patchSettingsSection('advanced', (nodes) => [
  ...nodes,
  { kind: 'field', path: 'settings', field: { key: 'robots', label: 'Robots', type: 'text' } },
], { targetTypes: ['page'], priority: 10 });
```

Settings values use the target object's draft/publish lifecycle and are saved
separately from the block tree. Admin UI for defining custom settings fields is
not provided by this editor extension API.

Plugins may contribute CSS autocomplete options to the built-in Custom CSS
editor. A provider receives the CodeMirror completion context plus the settings
target (`targetType`, `targetId`, `settings`), optional selected `block`,
`fieldKey`, and current CSS `value`. The target type is `page`, `template`, or
`block`. It returns an array of CodeMirror completion options, synchronously or
as a Promise; the registration returns an unregister callback.

```js
const unregisterCssCompletions = sidebar.registerCssAutocompleteProvider(
  ({ targetType, completionContext }) => [
    { label: '--brand-color', type: 'variable', detail: `Shared ${targetType} color` },
    { label: 'var(--brand-color)', type: 'text', apply: 'var(--brand-color)' },
  ],
  { priority: 20 },
);
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
`batch_processing` and `scheduler_default` with persistent workers for sustained
throughput, or use the bounded `iikiti:maintenance:run` command / post-response
fallback for low-volume deployments.

The admin library exports `BatchProgress` from the `@iikiti/ui` alias. Plugins
can embed it in custom screens with the shared API client and a batch ID. Its
single event endpoint supports polling (`mode=poll`) and SSE (`mode=stream`);
the SSE reader uses authenticated `fetch` so API tokens stay in headers.
