# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

> **Kilo workspace note:** every code change made by Kilo in this workspace
> appends a dated entry under the relevant section below (create the section if
> it does not exist). Keep entries brief: one line per changed behaviour, grouped
> under `Added`, `Changed`, `Removed`, `Fixed`, and `Security`.

## [Unreleased]

> Last updated: 2026-09-26

### Added
- 2026-09-22: Wildcard role hierarchy patterns (`ROLE_*`, `ROLE_PLUGIN_*`,
  `ROLE_SITE_*`, `ROLE_CONTENT_*`, `ROLE_MOD_*`, `ROLE_BLOG_*`, `ROLE_*_MODERATOR`,
  and tier-scoped variants) in `config/packages/security.yaml`, resolved by
  Symfony 8.2's native `RoleHierarchy` at runtime.
- 2026-09-22: `DynamicRoleHierarchy` structured config class
  (`src/Security/DynamicRoleHierarchy.php`) encapsulating the static role chain
  and wildcard pattern definitions as PHP constants with expansion and validation
  methods.
- 2026-09-22: `DynamicRoleHierarchyPass` compiler pass
  (`src/DependencyInjection/Compiler/DynamicRoleHierarchyPass.php`) that merges
  static + wildcard hierarchy with enum-registered roles into the
  `security.role_hierarchy.roles` container parameter at compile time.
- 2026-09-22: `has()` and `hasName()` query methods on
  `DynamicEnumInterface` and `DynamicBackedEnumInterface` for checking role
  registration by value or name.
- 2026-09-22: `validateValue()` hook in `DynamicBackedEnumerator` with
  `ROLE_[A-Z0-9_]+` naming enforcement in `UserRoleEnum`.
- 2026-09-26: `TemplateProcessor` (create/update) state processor backing the
  admin Templates POST/PUT operations on `TemplateResource`.
- 2026-09-26: Admin Templates `form` screen descriptors (edit + new) and
  list→edit row navigation wired in the admin SPA (`App.svelte`,
  `lib/router.js`).
- 2026-09-26: Generic admin form component now round-trips edited values
  (`Form.svelte` exposes `onsubmit(data)` + children slot) and loads the
  existing record on edit with `json`-typed field encode/decode
  (`GenericFormPage.svelte`, `Form.svelte`).

### Changed
- 2026-09-22: Symfony dependency baseline upgraded from 8.1 to 8.2
  (`composer.json` and all `symfony/*` constraints).
- 2026-09-22: `PermissionChecker` now injects `RoleHierarchyInterface` and
  expands user roles via `getReachableRoleNames()` before permission resolution,
  ensuring inherited and wildcard-matched roles are checked consistently.
- 2026-09-22: `RoleProcessor::delete()` now schedules a container rebuild via
  `PluginContainerRebuilder` after removing a custom `Role` entity, so the
  compiled role hierarchy stays in sync with DB state.
- 2026-09-26: Documentation for wildcard role hierarchy, `debug:roles` command,
  and dynamic role naming conventions added to `docs/roles-and-acls.md`.

### Fixed
- 2026-09-26: Logout via direct navigation to `/logout` no longer rejects with a
  "no CSRF token" error — CSRF protection disabled on the logout endpoint in
  `config/packages/security.yaml`; the firewall's `LogoutListener` no longer
  requires a `_token` query parameter.
  template — root cause was duplicate object IDs in the `objects` table.
- 2026-09-26: `DbObject::getId()` returns null instead of throwing on a
  transient (non-persisted) entity.
- 2026-09-26: `DynamicDiscriminatorMapListener` phpstan level-6 errors
  (redundant `ClassMetadata` guard, generic type annotation, `EntityManager`
  cast before `getConfiguration()`).
- 2026-09-26: Front-end crash `l is not a function` on the live page — the
  `iikiti` framework exposed `domReady`/`onLoad` as promises, but
  `index.js` invoked `domReady()` as a function; fixed to `domReady.then()`.
- 2026-09-26: `Variable "iikiti_editor_mode" does not exist` on `/login` and
  any page rendered outside `PageRendering` — `iikiti_editor_mode`/`iikiti_config`
  are now registered as Twig globals (defaulting to `false`/`null`) and set
  per-request by `PageRendering`/`TemplateRenderer`.
- 2026-09-26: The seeded "Edit this page with `?edit`" demo text block no longer
  leaks to public visitors — `BlockRenderer` suppresses that placeholder block
  outside editor mode (it remains visible to editors so they can replace it).
- 2026-09-26: Editor mode never activated for non-`ROLE_SYSTEM` admins (the
  "Edit this page" hint stayed hidden) — `Role::can()`/`UserGroup::can()` now
  match permission keys case-insensitively: keys are stored lowercase (`page`)
  but callers pass the entity type capitalised (`Page`/`Template`).
- 2026-09-26: `login.twig` no longer returns HTTP 500 on a failed login — the
  `error` variable is a string message (set as `AuthenticationException::getMessage()`),
  rendered directly instead of via `error.messageKey`.
- 2026-09-26: Editor presence probe no longer 404s — `Editor.svelte` now builds the
  room URL from `contextType`/`contextId` (`/api/editor/room/template/26`) instead of
  reusing the `config.room` WebSocket channel name (`room/template:26`), which produced
  `/api/editor/room/room/template:26`.
- 2026-09-26: Editor toolbar banner now has readable button text (`#111827` on the
  light-gray button) and a dark theme (dark background, light text) via
  `prefers-color-scheme`/`.dark`.

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
- 2026-09-19: Administration UI: Svelte 5 (runes mode) SPA at `/admin/*` with
  client-side routing, sidebar navigation from `/api/admin/menu`, and list/detail
  views for users, user groups, roles, applications, sites, site groups.
- 2026-09-19: Default roles seeded via migration: System, Admin, Site Manager,
  Manager, Editor, Author, Member, Non-Member — each with immutable default
  permissions and non-deletable. Editor and Author can be hidden by admins.
  New roles are registered in `UserRoleEnum` and the role hierarchy in `security.yaml`
  extends from `ROLE_NON_MEMBER` up to `ROLE_SYSTEM`.
- 2026-09-19: ACL (Access Control List) system: `PermissionChecker` service
  resolves permissions by checking user roles (via Role entities) and user group
  memberships (via UserGroup ACL permissions). Object-type-level granularity
  with wildcards (`*` for any object type or any action).
- 2026-09-19: `Role` entity with `default_permissions` (immutable) and
  `custom_permissions` (editable). `UserGroup` and `SiteGroup` DbObject entities
  added for the `objects` table (types `user_group` and `site_group`) with
  repositories.
- 2026-09-19: Audit logging: `AuditLogEntry` entity + `AuditLogger` service +
  `AuditSubscriber` Doctrine listener that automatically records all entity
  inserts, updates, and deletes with before/after state. Extended context in
  debug mode (stack traces, request details).
- 2026-09-19: `AdminExtensionInterface`, `AdminMenuRegistry`, `CoreAdminExtension`,
  `AdminMenuItem`, `AdminApiResource` — plugin extensibility framework for the
  admin UI. Plugins contribute menu items and API resources via the
  `iikiti.admin.extension` DI tag.
- 2026-09-19: Admin API resources under `/api/admin/*`: user-group CRUD,
  site-group CRUD, role read/write (custom permissions only), audit log listing,
  admin menu aggregation.
- 2026-09-19: `ApiTokenManager` service for ephemeral API tokens, enabling the
  admin SPA to call stateless `/api` endpoints while authenticated via session.
- 2026-09-19: Documentation: `docs/admin-ui.md`, `docs/roles-and-acls.md`,
  `docs/audit-logging.md`, `docs/admin-extensibility.md`.
- 2026-09-19: Core admin component library with typed reusable Svelte 5 components
  (`AdminLayout`, `DataTable`, `PageHeader`, `LoadingState`, `ErrorBoundary`, `EmptyState`,
  `Button`, `Badge`, `Tabs`, `Breadcrumb`, `Pagination`, `SearchForm`, `Dialog`,
  `DetailView`, `Card`, `Form`, `FormField`, `TextInput`, `TextareaInput`,
  `SelectInput`, `CheckboxInput`, `ToggleInput`) with barrel exports under
  `@iikiti/admin` import alias.
- 2026-09-19: Dynamic screen registry — `AdminExtensionInterface::getAdminScreens()`
  lets plugins declare admin screens; SPA fetches `/api/admin/screens` to build
  its route table instead of using a hardcoded map.
- 2026-09-19: Generic list/detail/form page components (`GenericListPage`,
  `GenericDetailPage`, `GenericFormPage`) rendered from screen metadata (columns,
  fields, API path) so plugins can add fully functional admin pages without
  writing custom Svelte.
- 2026-09-19: Plugin UI bundle support — plugins can ship compiled Svelte bundles
  served via `PluginAssetController` at `/admin-plugins/{slug}/...` and loaded
  on demand by the SPA via dynamic `import()`, using core components from
  `@iikiti/admin`.
- 2026-09-19: `AdminExtensionTrait` providing an empty default
  `getAdminScreens()` implementation for plugins that only contribute menu items.

### Changed
- 2026-09-19: `AdminExtensionInterface` now requires `getAdminScreens()`; existing
  implementations should use `AdminExtensionTrait` to avoid a breaking change.
- 2026-09-19: `AdminLayout` accepts `currentPath` as a prop, fixing a duplicate-state
  race condition between the layout and the root `App.svelte`.
- 2026-09-19: `App.svelte` route resolution is now driven by the `/api/admin/screens`
  manifest fetched at runtime; static route imports are fallback-resolved via a
  `CORE_COMPONENTS` registry.
- 2026-09-19: `AdminMenuRegistry::getScreens()` aggregates and sorts screen
  descriptors from all registered extensions.
- 2026-09-19: `PluginRegistry` now exposes `getPath(string $slug): ?string` to
  resolve an active plugin's filesystem path for asset serving.
- 2026-09-19: `webpack.config.mjs` adds `@iikiti/admin` alias pointing to the
  component barrel export; `tsconfig.json` gains matching path mappings.

### Deprecated
- 2026-09-19: `AdminExtensionInterface::getResources()` — use `getAdminScreens()`
  instead, which provides richer screen descriptors including component type, API
  path, and column/field config.

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
- 2026-09-23: `TemplateResolverTest` now stubs `EntityManagerInterface` (returning
  an `EntityRepository` stub) instead of passing an `ObjectRepository` directly,
  fixing 4 `TypeError` errors when constructing `TemplateResolver`.
- 2026-09-23: Removed deprecated `ReflectionProperty::setAccessible(true)` calls
  in `DraftPublishWorkflowTest`, `TemplateRendererTest`, and
  `TemplateResolverTest` (no-op since PHP 8.1, deprecated in PHP 8.5).
- 2026-09-23: `PluginBundleInterface` now extends
  `Symfony\Component\DependencyInjection\Kernel\BundleInterface` instead of
  the deprecated `Symfony\Component\HttpKernel\Bundle\BundleInterface`.
- 2026-09-23: `SearchService` now type-hints
  `Symfony\Contracts\EventDispatcher\EventDispatcherInterface` instead of the
  deprecated `Symfony\Component\EventDispatcher\EventDispatcherInterface` alias.
- 2026-09-20: `ApiTokenRepository` no longer applies the default `site` filter
  on queries, fixing a DQL parse error (`has no field or association named site`)
  when accessing `/admin`. `ApiToken` does not extend `DbObject` and has no
  `site` association, so `filterBySite` now defaults to `false` for its repository.
  Also changed `RepositoryOptionCheckTrait::_checkOption` to use `static::`
  (late static binding) instead of `self::` so repository subclasses can override
  `_defaultOption`.
- 2026-09-20: `migration Version20260920074000` creates the `api_tokens` table
  for the `ApiToken` entity (token, expires_at, user_id) with FK to `objects(id)`.
  Also adds the missing primary key on the `objects` table that `DbObject`
  declares via `@Id`.
- 2026-09-20: `migration Version20260920075000` creates the `audit_log_entries`
  table for the `AuditLogEntry` entity (with indexes on object_type/object_id,
  user_id, created_at, and FK to `objects.id`).
- 2026-09-20: Fixed `migration Version20260919140000` — added schema qualification
  (missing when the migration was previously broken) and corrected PostgreSQL
  boolean binding (`'true'`/`'false'` strings instead of PHP booleans that
  arrived as empty strings).
- 2026-09-20: `AuditSubscriber::onFlush()` now calls `$unitOfWork->computeChangeSets()`
  after persisting audit entries, ensuring changesets for newly-persisted
  `AuditLogEntry` entities are computed before the outer `flush()` executes INSERTs.
  Without this, Doctrine's `prepareInsertData()` produced empty parameter arrays
  ("bind message supplies 0 parameters").
- 2026-09-20: `AuditLogger::log()` no longer calls `flush()` when invoked from
  `AuditSubscriber::onFlush()`, fixing an infinite recursion (onFlush → log →
  flush → onFlush) triggered by the first `ApiToken` insert on `/admin`.
  Added a `$flush` parameter (default `true`) to `log()` and `logSystemAction()`;
  the subscriber passes `false` so the outer flush persists audit entries
  alongside the original entities.
- 2026-09-20: Removed `normalizationContext` groups from `AdminMenuResource` and
  `AdminScreenResource` so their `items` property is included in API responses.
  The groups (`menu:read`, `screens:read`) had no matching `#[Groups]` annotations
  on the properties, causing the entire payload to be serialized as `{}`.
- 2026-09-20: Frontend `ApiClient.extractItems()` now handles API Platform's Hydra
  collection wrapping (`hydra:member`) for `getMenu()`, `getScreens()`, and
  `getResources()`, fixing "items is not iterable" and "find is not a function"
  runtime errors in the admin SPA.
- 2026-09-20: Admin SPA now renders a fixed full-screen loading overlay ("Loading
  administration…") until menu and screen data is fetched, replacing the previous
  behaviour where the server-rendered spinner persisted alongside the mounted app.
  `admin.js` also clears the target element before `mount()` since Svelte 5
  appends rather than replaces.
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

### Fixed
- 2026-09-23: Removed `DbObject::setProperties()` override that called `setProperty()` for each
  property during collection iteration, causing `ArrayCollection::set()` to leave stale entries
  at wrong indices (properties from other objects appearing at Template property keys like
  "assignments", "blocks", "draft_version"). The override also reassigned `ObjectProperty.object`
  via `setObject()` without syncing the `object_id` column. Doctrine's own back-reference
  hydration (`PersistentCollection::hydrateSet`) handles this correctly.
- 2026-09-23: Added `DynamicDiscriminatorMapListener` (`src/Event/Listener/`) that dynamically
  registers all `DbObject` STI subclasses (Template, Site, User, Page, Application, Lexeme,
  SiteGroup, UserGroup, and plugin-defined subclasses) into the discriminator map at metadata
  load time. Without an explicit `DiscriminatorMap`, Doctrine's auto-discovery could produce a
  stale or incomplete map, causing STI collection hydration to load properties from unrelated
  objects (e.g. Site/User properties leaking into a Template's `properties` collection).
- 2026-09-23: Migration `Version20260923054500` fixes duplicate IDs in the `object_properties`
  table (the `id` column lacked a primary key constraint, allowing the same `id` to be
  shared across properties belonging to different objects). Duplicate rows are reassigned
  to fresh identity-generated IDs and a `PRIMARY KEY (id)` constraint is added, preventing
  the identity-map collisions that caused `ObjectProperty` entities from the wrong owner
  to be returned when loading a Template's properties.

### Added
- 2026-09-23: Front-end block editor backend: `BlockType` registry + core blocks
  (container, dynamic, heading/text, image, video_embed, social_embed, query),
  `BlockRenderer` (Twig, editor-metadata gating), embed resolver (YouTube/Vimeo +
  oEmbed social providers) and a safe, parameterized query executor
  (`src/Web/BlockEditor/`).

### Added
- 2026-09-23: `Template` entity + `TemplateRuleInterface` (object/objectType/site rules)
  + `TemplateResolver` + `TemplateRenderer` (region validation — a `main` content
  region is required or an error is shown) + `PageRendering` live-page pipeline;
  `HomeController` and `/{slug}` object-page route render through the pipeline.

### Added
- 2026-09-23: Editor API (`/api/editor/context`, `save`, `publish`, `render-block`)
  and a WebSocket room-admission gate; draft/publish save workflow with ETag
  concurrency; cache-backed presence store.

### Added
- 2026-09-23: `iikiti` JS framework entry (dependency+version-aware loader with
  `domReady`/`onLoad` promises, notifications, plugin auto-loading) and the
  `editor` Svelte 5 (runes) entry that mounts on `?edit` for authorised editors.

### Added
- 2026-09-23: Plugin manifest `editor_ui`/`site_ui` keys +
  `PluginRegistry::getManifests()`.

### Changed
- 2026-09-23: `base/layout.twig` loads the `iikiti` framework on all pages and
  the editor chunk only in edit mode; editor bootstrap config is injected via
  `FrontendConfigProvider` only when the user can edit.

### Fixed
- 2026-09-23: `SelectInput` Svelte 5 incompatibility (dynamic `multiple` with
  `bind:value`) — now uses static-`multiple` branches.

### Added
- 2026-09-23: Front-end workflow engine: `WorkflowSchemaExtractor` (FormType →
  JSON field schema), `/flow/{flow}/step` step-schema API, and a dynamic
  `Workflow.svelte` component loaded by iikiti when a `data-flow` marker is
  present (progressive enhancement of MFA / multi-step forms).
