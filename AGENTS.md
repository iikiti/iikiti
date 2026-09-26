# Project AGENTS.md for Kilo

> This is a *project-level* Kilo configuration document.
> It supplements (does not replace) the host/workspace `AGENTS.md`.
> See `.kilo/plans/` for active work plans.

## Changelog discipline

`CHANGELOG.md` at this repo's root records every notable change.
When Kilo makes a code/config change in this workspace, **append a dated entry**
(under the current work date, e.g. `- 2026-09-13: …`) to the matching section
(`Added`, `Changed`, `Removed`, `Fixed`, `Security`) within the `## [Unreleased]`
block before finishing the task.

- Prefer one concise line per changed behaviour.
- If the section does not yet exist, create it.
- Keep the `## [Unreleased]` heading as the top section; move its contents into a
  dated release block only as part of an explicit release step.

This rule is enforced as part of Kilo's own work-checklist for this workspace.

Keep the changelog split in separate files (per day) or per version in the workspace directory "changelog".
Keep only the latest 5 days or versions in the top level CHANGELOG.md.

## Testing

You can access the database and run queries through the doctrine CLI commands. For a list, use command `php bin/console list doctrine`.
You can use action `dbal:run-sql` ro run SQL directly on the database. Use this only on local or development environments.

## Documentation

For changes made that require configuration changes or user interaction, make sure to add or update existing documentation under /docs and include description of the feature, a walkthrough of any relevant workflows, and installation or configuration steps required to be completed.

## API-First

Virtually all CMS data and actions should be available through the API in a secure way.

Business logic should be handled by API methods. For instance, a controller for the web front or back-end should call the necessary API methods unless not strictly required.

## Plugin system

- **"Plugin" is the primary term.** Use `Plugin*` class names, `iikiti:plugin:*`
  commands and `/api/admin/plugins/*` routes. "Extension" is a legacy alias
  (e.g. `getEnabledExtensions()`) kept for backward compatibility only.
- Plugins are full Symfony bundles that extend `iikiti\CMS\Plugin\PluginBundle`
  and are discovered at compile time from `cms/extensions/active/`.
- The `iikiti\` vendor namespace is reserved for plugins built by iikiti.
  Third-party plugins use their own vendor namespace.
- Every plugin ships a `plugin.json` manifest; see `docs/plugin-development.md`.
- Installation/updates go through the store and enforce checksum, Ed25519
  signature, namespace and review-state checks; see `docs/plugin-security.md`.
- Management is available via `iikiti:plugin:*` commands and the admin API; see
  `docs/plugin-api.md`.
- When changing behaviour here, update the relevant plugin documentation and add
  a `CHANGELOG.md` entry.

## YAML Configuration

Use PHP Attributes where possible then fall back to YAML configuration where it is not.