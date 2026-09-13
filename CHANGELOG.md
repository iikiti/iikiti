# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

> **Kilo workspace note:** every code change made by Kilo in this workspace
> appends a dated entry under the relevant section below (create the section if
> it does not exist). Keep entries brief: one line per changed behaviour, grouped
> under `Added`, `Changed`, `Removed`, `Fixed`, and `Security`.

## [Unreleased]

> Last updated: 2026-09-13

### Added
- Multi-factor authentication driven by the abstract workflow: a `MfaChallengeController`
  (`/mfa/challenge`) walks the user through `mfa_authentication` workflow steps, each
  rendered with a Symfony form type.
- Multi-factor challenge steps for e-mail (one-time code), TOTP authenticator apps,
  and single-use backup codes (`src/Workflow/Step/Mfa/`).
- Generic multi-step form support so the same workflow engine powers any form-based
  flow: `FormStepInterface`, `AbstractFormWorkflowStep`, `MultiStepFormRunner`,
  `WorkflowSessionStorage`, `WorkflowFactory`, and `MultiStepFormController`
  (`/forms/{workflow}`).
- Runtime-built dynamic forms from a field-definition list: `DynamicFormType` and
  `DynamicFormStep` + `DynamicFormStepProvider` (seeded via the `steps` context),
  preparing the path for a future admin UI.
- Server-side MFA attempt limiter (`MfaAttemptLimiter`) with a configurable
  attempt cap and temporary lockout window.
- `MfaAttemptLimiter`, `EmailTokenStrategy`, `TotpTokenStrategy`, backup-code
  handling, and a proxy `AuthenticationToken` now live in `src/` (see `src/Authentication/`,
  `src/Security/`).
- Configuration parameters (`config/packages/mfa.yaml`): `mfa.sender_email`,
  `mfa.max_attempts`, `mfa.lockout_seconds`.
- `CHANGELOG.md`, `/docs/mfa-workflow.md`, and project `AGENTS.md`.
- Database query result caching layer (Doctrine ORM result cache backed by the
  `cache.database` Symfony cache pool): repository methods (`find`, `findBy`,
  `findOneBy`, `findAll`, `findByProperty`, `findOneByProperty`) and API
  Platform collection/item queries are cached for reads.
- Pluggable caching strategy architecture (`CachingStrategyInterface`) with
  `DoctrineResultCacheStrategy` (default), `NoCacheStrategy`, compile-time
  registration via the `iikiti.cache_strategy` DI tag and runtime registration
  through `CachingStrategyRegistry::register()`; strategies are enumerable via
  `getAvailableStrategies()` for admin UIs.
- Per-request cache disable for batch processing: `CacheState::disable()` is
  triggered by the `_disable_db_cache` request attribute, routes matching the
  configured batch pattern, console commands using `DisablesDatabaseCache`,
  and a `_cache_strategy` request attribute can override the active strategy.
- Per-query cache disable via repository `$options` (`cache: false`, and
  `cacheTTL` to override the default lifetime).
- Strategy selection through configuration (`iikiti_cache.strategy`),
  environment variables (`CACHE_STRATEGY`, `CACHE_DATABASE_TTL`,
  `CACHE_DATABASE_ENABLED`, `CACHE_DATABASE_BATCH_PATTERN`), and dynamically
  from a database configuration value via `DatabaseConfigStrategyResolver`.
- Generation-based cache invalidation: `CacheInvalidationSubscriber` bumps a
  per-entity-class generation on `postFlush` so stale query results become
  unreachable (TTL acts as a safety net).
- 2026-09-13: Plugin subsystem with compile-time bundle discovery/registration
  (`PluginBundle`, `PluginLoader`, `PluginRegistry`, `PluginValidator`) and the
  `plugin.json` manifest + namespace standard.
- 2026-09-13: Secure plugin downloads with SHA-256 checksum and Ed25519
  signature verification and store review-state enforcement (`PluginDownloader`,
  `PluginState`, `PluginSource`, `PluginAutoloader`).
- 2026-09-13: Plugin lifecycle events and hooks (`PluginEvents`, `PluginEvent`,
  `PluginLifecycleInterface`, `PluginLifecycleHandler`).
- 2026-09-13: `iikiti:plugin:*` CLI commands (list/install/update/remove/enable/
  disable/verify/configure/migrate) and the admin REST API
  (`/api/admin/plugins/*`, `PluginInfo`, `PluginOperation`).
- 2026-09-13: `plugin_registry` table (`PluginRecord` entity, repository and
  migration) for plugin install/version auditing.
- 2026-09-13: Plugin documentation (`docs/plugin-development.md`,
  `docs/plugin-api.md`, `docs/plugin-security.md`).

### Changed
- MFA is now triggered by an `AuthenticationTokenCreatedEvent` subscriber that wraps
  the login token in an unauthenticated proxy until the challenge succeeds.
- `Workflow` now dispatches the previously-missing `WorkflowEvents` step/completion
  constants on navigation, exposes `getCurrentStepIndex()`,
  `mergeContext()` and `submitCurrentStep()`.
- `MfaStepProvider` builds steps from the workflow context (seeded once at challenge
  start) instead of re-resolving user preferences on every request.
- The MFA challenge persists the originally requested path and returns the user
  there after a successful challenge.
- `MfaCodeMailer` is a lazy service and its sender is env-configurable
  (`MFA_SENDER_EMAIL`).
- 2026-09-13: Plugin enablement is per-site with batch activation/update across
  all sites; container rebuilds are batched into a single `cache:clear`.
- 2026-09-13: Configuration reads the `plugins` key (the `extensions` key is
  retained as a legacy alias) and `getEnabledPlugins()` supersedes
  `getEnabledExtensions()`.
- 2026-09-13: `SiteRegistry` no longer fails when there is no HTTP request, so
  CLI commands and workers can access the database.
- 2026-09-13: `CacheInvalidationSubscriber` now listens to Doctrine `onFlush`/
  `postFlush` with the correct event argument types.
- 2026-09-13: A plugin can now be enabled/disabled for a subset of sites; the
  `active/` symlink and container rebuild are correctly applied for scoped
  operations.

### Removed
- `iikiti/mfa` vendor bundle and its VCS repository dependency from `composer.json`;
  MFA primitives are now part of the application.
- `config/packages/iikiti_mfabundle.yaml` and the `iikitiMultifactorAuthenticationBundle`
  registration.
- Replaced `iikiti\MfaBundle\Authentication\Authenticator` as a custom authenticator
  with the workflow-driven controller; replaced the vendor `AccessHandler` with
  `iikiti\CMS\Security\AccessHandler`.
- 2026-09-13: Removed the legacy `iikiti\CMS\Loader\Extensions` runtime loader and
  the `ExtensionConfigurationTrait` alias (replaced by the plugin subsystem and
  `PluginConfigurationTrait`).

### Fixed
- `StepSubscriber` step-provider wiring was misconfigured (passed a literal array
  instead of a tagged iterator), so no workflow ever received steps; rewired in
  `config/services.yaml`.
- `AuthenticationToken` now serializes its `authenticated` flag so MFA completion
  survives the session round-trip.
- The MFA proxy token no longer exposes the wrapped user's roles before the
  challenge is satisfied, so `ROLE_*` checks cannot pass pre-MFA.
- `EmailTokenStrategy` now stores a hash of the issued code and validates against it,
  and expired e-mail challenges are reissued.
- Open-redirect hardening on the post-challenge target path.
- The MFA subscriber skips credential-less authenticators (e.g. the stateless API
  token), so API authorization is unaffected.
- 2026-09-13: Plugin bundle classes are now autoloaded before Symfony instantiates
  the cached bundle list, fixing plugins not loading from a warm container cache.
- 2026-09-13: Plugin admin API now returns `404`/`422` for failed operations
  instead of a `200` with `success: false`.
- 2026-09-13: Plugin `installPackage`/`updatePackage` now validate declared
  dependencies and reject slug-mismatched packages on update.
- 2026-09-13: `iikiti:plugin:update --version` with no slug is now rejected
  instead of forcing that version onto every installed plugin.
- 2026-09-13: `iikiti:plugin:migrate` now merges `extensions` into `plugins`
  (per-key, `plugins` precedence) rather than skipping sites that already have a
  `plugins` entry.
- 2026-09-13: A plugin can no longer be activated on prod without `published`
  state; manually placed plugins default to `pending_review`.
- 2026-09-13: `PluginRegistry`/`PluginAutoloader`/`PluginLoader` optimised:
  single-pass active-site maps for listing, cached filesystem scans,
  batch/single-flush and symlink-sync fixes, longest-prefix autoload
  pre-sorted, and early-boot discovery kept metadata-only.


### Security
- Brute-force protection: failed MFA attempts are counted in a per-user,
  server-side cache lock (default 5 attempts, 300 s lockout).
- Challenge codes expire (e-mail TTL 300 s) and are single-use per workflow step.
- E-mail codes are stored only as a one-way hash in the workflow context.
- 2026-09-13: The `iikiti\` vendor namespace is reserved for first-party plugins;
  activation symlinks must resolve inside `cms/extensions/installed/`; plugin
  archives are rejected for zip-slip paths; and third-party store URLs and
  non-published plugin states are blocked on production.
- 2026-09-13: `object_properties.creator_id` is now nullable so plugin
  configuration can be written from CLI/admin contexts without a user; and the
  `ObjectProperty` creation path was fixed (`DbObject::setProperty`).
- 2026-09-13: Plugin `PLUGIN_REQUIRE_SIGNATURE` and `PLUGIN_AUTO_REBUILD` are now
  environment-configurable so production defaults can be set per environment.
- 2026-09-13: Plugin install audit (`plugin_registry`) now writes via the DBAL
  connection rather than the ORM unit of work, preventing EM-poisoning of
  subsequent site-activation flushes.
