# Plugin Management (CLI and API)

Plugins are installed, updated and removed through an administrative interface
(CLI and REST API). All operations are admin-only and should be performed by an
administrator or an administrative AI acting on their behalf.

## CLI commands

All commands live under the `iikiti:plugin` namespace.

```
iikiti:plugin:list                         List installed plugins and active sites
iikiti:plugin:install {slug}               Download and install from the store
iikiti:plugin:update [slug] [--dry-run]    Check for and apply updates
iikiti:plugin:remove {slug} [--force]      Uninstall a plugin
iikiti:plugin:enable {slug}                Enable for sites (default: all)
iikiti:plugin:disable {slug}               Disable for sites (default: all)
iikiti:plugin:verify {slug}                Verify manifest, bundle class and link
iikiti:plugin:configure {slug}             Read/update per-site configuration
iikiti:plugin:migrate                      Migrate legacy extensions config keys
```

Site scope options (where applicable): `--site=ID` (repeatable) and
`--all-sites` (default). Install/update accept `--version`, `--store` and, for
updates, `--dry-run`.

Examples:

```bash
# Install the latest version and enable it on every site
php bin/console iikiti:plugin:install acme-blog

# Enable on two specific sites only
php bin/console iikiti:plugin:enable acme-blog --site=12 --site=15

# Preview updates without applying
php bin/console iikiti:plugin:update --dry-run
```

> Enabling, disabling, installing or removing a plugin changes which bundles are
> registered, so the container is rebuilt. Batch operations rebuild once at the
> end.

## REST API

Admin-only (`ROLE_ADMIN`). All routes are under `/api/admin/plugins`.

| Method | Endpoint | Action |
|--------|----------|--------|
| GET | `/api/admin/plugins` | List installed plugins |
| GET | `/api/admin/plugins/{slug}` | Plugin details |
| POST | `/api/admin/plugins/install` | Install a plugin |
| POST | `/api/admin/plugins/update` | Update a plugin |
| POST | `/api/admin/plugins/remove` | Uninstall a plugin |
| POST | `/api/admin/plugins/enable` | Enable a plugin |
| POST | `/api/admin/plugins/disable` | Disable a plugin |
| POST | `/api/admin/plugins/verify` | Verify a plugin |

Command body:

```json
{
  "slug": "acme-blog",
  "version": "1.2.0",
  "sites": ["12", "15"],
  "storeUrl": null,
  "dryRun": false
}
```

`sites` is a list of site ids; an empty list means **all sites**. The response
echoes `success`, `message` and a `result` payload. Failures return a non-200
status (`404` when the plugin is not installed, `422` for state/security/store
errors) with the message in the error body, so clients can detect failed
operations.

## Configuration

Environment variables (see `.env`):

| Variable | Purpose |
|----------|---------|
| `PLUGIN_STORE_URL` | Store base URL (default `https://store.iikiti.com`) |
| `PLUGIN_ALLOW_NON_APPROVED` | Allow non-published plugin states (non-prod only) |
| `PLUGIN_ALLOW_THIRD_PARTY_UPDATES` | Allow third-party store URLs (non-prod only) |
| `PLUGIN_STORE_PUBLIC_KEY` | Ed25519 public key (base64) for signature checks |

Parameters are defined in `config/packages/plugins.yaml`.

## Store API (consumed by the CMS)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/plugins/{slug}/{version}/download` | Package metadata, checksum, signature and state |
| GET | `/api/v1/plugins/{slug}/updates?current={v}` | Latest version, if any |
