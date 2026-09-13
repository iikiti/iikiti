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
