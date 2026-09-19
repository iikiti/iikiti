# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

> **Kilo workspace note:** every code change made by Kilo in this workspace
> appends a dated entry under the relevant section below (create the section if
> it does not exist). Keep entries brief: one line per changed behaviour, grouped
> under `Added`, `Changed`, `Removed`, `Fixed`, and `Security`.

## [Unreleased]

> Last updated: 2026-09-19

### Added
- 2026-09-19: Full-text search feature with PostgreSQL `tsvector` + GIN index engine
  as the default, abstracted behind `SearchEngineInterface` + `SearchEngineRegistry`
  for future Elasticsearch support (`src/Search/Strategy/`).
- 2026-09-19: Search index configuration entities (`SearchIndex`, `SearchIndexField`,
  `SearchAnalyzerLayer`, `SearchFilter`, `SearchConfigGroup`, `SiteGroup`) with
  Doctrine ORM mappings, repositories, and migrations (schema-qualified).
- 2026-09-19: Default frontend and admin search index configurations seeded via
  migration: frontend (deletable), admin (system-locked, non-deletable, fields
  editable), plus a default analyzer with lowercase/stop/stemmer layers.
- 2026-09-19: Lucene/ElasticSearch-inspired analyzer layers (tokenizer, charfilter,
  tokenfilter, ngram, edgengram, lowercase, uppercase, stop, stemmer, synonym,
  normalizer) with type enum and JSON options column.
- 2026-09-19: Index field source types: `column` (objects table), `property`
  (ObjectProperty store), `virtual` (custom SQL), and `alias` (field mapping).
- 2026-09-19: Custom search filters with visibility controls (public frontend,
  admin-only, role-restricted) and mode (query-time/index-time). Filter
  implementations via `SearchFilterInterface` + `iikiti.search_filter` DI tag.
- 2026-09-19: Site grouping: `SiteGroup` and `SearchConfigGroup` entities allow
  assigning search configurations to sites or site groups as a unit.
- 2026-09-19: Admin UI API Platform resources: CRUD on search indexes, fields,
  layers, filters, config groups, and site groups. System-locked protection
  enforced on delete and engine changes. Index operations (create/drop/rebuild)
  exposed as custom operations.
- 2026-09-19: CLI commands: `iikiti:search:config:init`, `iikiti:search:config:list`,
  `iikiti:search:index:create`, `iikiti:search:index:drop`, `iikiti:search:index:rebuild`,
  `iikiti:search:rebuild-all`, `iikiti:search:engines`.
- 2026-09-19: `SearchIndexListener` Doctrine lifecycle listener (onFlush/postFlush)
  keeps search index tables in sync when `DbObject` entities are persisted, updated
  or removed. Failures are logged, never thrown.
- 2026-09-19: `SearchService` orchestrates search: resolves site-scoped configs,
  applies filters by visibility/role, dispatches lifecycle events for plugin hooks.
- 2026-09-19: `IndexManager` manages index lifecycle (create/drop/rebuild/sync).
- 2026-09-19: PostgreSQL function catalog extended: `TO_TSVECTOR`, `TS_RANK`,
  `TS_RANK_CD`, `TS_HEADLINE`, `SETWEIGHT` added to the platform strategy.
- 2026-09-19: `SearchExpressionBuilder` for building tsvector expressions, ranking,
  and highlighting via the query builder API.
- 2026-09-19: Search events: `iikiti.search.search`, `iikiti.search.autocomplete`,
  `iikiti.search.filter_resolve`, `iikiti.search.filter_apply`,
  `iikiti.search.index_created`, `iikiti.search.index_dropped`,
  `iikiti.search.index_rebuilt`.
- 2026-09-19: `SearchableRepositoryInterface::search()` updated with `$options`
  parameter and `SearchResult` return type; `ObjectRepository::search()` delegates
  to `SearchService`.
- 2026-09-19: Documentation: `docs/full-text-search.md` (developer guide) and
  `docs/search-admin.md` (admin guide).

### Changed
- 2026-09-19: `ObjectRepository` constructor now accepts an optional `SearchService`
  dependency; all 6 concrete repositories updated to pass it through.
- 2026-09-19: `FullTextSearch` service stub deprecated; replaced by
  `SearchIndexListener` in `src/Search/Listener/`.
- 2026-09-13: Replaced `!tagged_iterator` YAML tags in `config/services.yaml` with
  PHP `#[AutowireIterator]` attributes on constructors, eliminating IDE/YAML linter
  "Unresolved tag" errors. Converted remaining `bind`/`arguments` entries to
  `#[Autowire]` and `#[Autoconfigure]` attributes across 17 service classes.
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
- Symfony Profiler "Database cache" panel (`DatabaseCacheDataCollector`)
  showing the active strategy, backend adapter, per-entity-class generation
  counters and registered strategies (dev/test).
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
- 2026-09-13: Safety-first database query builder (`src/Query/`) extending
  Doctrine DBAL's query builder for `SELECT`, `UPDATE` and `DELETE` statements
  with typed identifiers, parameter binding, common table expressions
  (including `WITH RECURSIVE`) and unions. Inline literal values in expression
  strings are rejected by default (`InlineValueException`); trusted SQL must
  opt in through `expr()->raw()`. Database syntax is abstracted behind
  `DatabasePlatformStrategyInterface` with a PostgreSQL strategy by default, so
  additional database strategies can be registered through the
  `iikiti.query.database_platform` DI tag or at runtime via
  `DatabasePlatformRegistry::register()`. New `QueryBuilderFactory` service
  (`QUERY_DEFAULT_PLATFORM`, `QUERY_SAFETY_ENABLED` configuration) and developer
  guide (`docs/query-builder.md`).

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
- 2026-09-13: Query builder inline-value enforcement now also rejects
  dollar-quoted string literals, hexadecimal/octal/binary, digit-separated and
  scientific-notation numeric literals, and boolean/`NULL` keyword literals
  (while still allowing `IS [NOT] NULL`); raw-string table, alias, column and
  `RETURNING` inputs are validated as identifiers, and the `LIKE ... ESCAPE`
  character is bound as a parameter rather than inlined.
- 2026-09-13: `PluginManager::recordInstall()` and `recordRemove()` now persist
  through the iikiti query builder instead of raw `Connection` SQL, binding the
  slug and using validated `plugin_registry` table names; `ObjectRepository`
  criteria predicates no longer interpolate field names via `sprintf` and
  validate each field through `Column::assertValid()`.
- 2026-09-13: Added an ORM-flavoured query builder (`src/ORM/`) that extends
  `Doctrine\ORM\QueryBuilder`, so `ObjectRepository::createQueryBuilder()` now
  returns a safe builder (parameter-binding expression helpers, inline-value
  enforcement and `Column` validation) while preserving entity hydration and the
  Doctrine result cache. A shared `InlineValueScanner` now powers the inline
  literal detection for both the DBAL and ORM builders. Criteria `WHERE` and
  `ORDER BY` field names are validated through `Column::assertValid()`.
