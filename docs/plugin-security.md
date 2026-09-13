# Plugin Security Model

The plugin subsystem is a supply-chain boundary. This document describes how the
CMS protects the host site and what is delegated to the store.

> **Scope:** automated and manual code review is performed by the **iikiti
> store**, not the CMS. The CMS enforces the store's review outcome through the
> plugin `state` field.

## Trust model

1. **Store review (out of scope here).** The store reviews submitted plugins
   (automated SAST and/or manual review), signs approved packages and publishes
   them.
2. **CMS enforcement.** The CMS verifies integrity, authenticity, namespace
   rules and review state at download **and** at activation/update/install — a
   manually placed plugin defaults to `pending_review` — so the documented
   production guarantee cannot be bypassed. Manage locally placed plugins with
   `PLUGIN_ALLOW_NON_APPROVED` in non-prod before enabling them.

## Plugin review states

The store returns a `state` for every version. The CMS policy is:

| State | Policy |
|-------|--------|
| `published` | Allowed in all environments |
| `pending_review` | Blocked |
| `testing` | Allowed only with `PLUGIN_ALLOW_NON_APPROVED=true` in non-prod |
| `development` | Allowed only with `PLUGIN_ALLOW_NON_APPROVED=true` in non-prod |
| `rejected` | Always blocked |

Production servers (`APP_ENV=prod`) never install non-`published` plugins, even
when the override is set.

## Supply-chain controls

| Control | Mechanism |
|---------|-----------|
| Integrity | SHA-256 checksum verification of the downloaded package (always) |
| Authenticity | Ed25519 signature verification over the package bytes |
| Namespace | `iikiti\` vendor prefix is reserved; third-party plugins are rejected if they use it |
| Path safety | Activation symlinks must resolve inside `cms/extensions/installed/` |
| Archive safety | Zip entries with `..`, absolute or drive-letter paths are rejected (zip-slip) |
| Source policy | Third-party store URLs are refused on production and unless explicitly allowed |

A package that is signed but has no configured public key, or that is unsigned
when signatures are required, is rejected and deleted from the cache.

## Runtime isolation

Plugins run in the same PHP process as the CMS (required for Symfony bundle
integration). Consequently:

- Plugin services must declare their permissions in `plugin.json`.
- Plugin API endpoints are protected by the standard Symfony security layer.
- Plugin permissions are additive; a plugin cannot grant itself more than it
  declares.
- A plugin that fails validation during boot is skipped and recorded under the
  `iikiti.plugins.errors` container parameter instead of taking down the site.

## Third-party stores

Third-party stores are *reskinned* frontends over the iikiti store
infrastructure. They may host exclusive plugins, but all packages are reviewed,
signed and distributed through the iikiti store. Third-party store URLs are
development/testing only and are blocked on production.

## Audit

Install/update/remove operations are recorded in the `plugin_registry` table
(see `migrations/`), and lifecycle events are dispatched under the
`iikiti.plugin.*` namespace for logging and monitoring.
