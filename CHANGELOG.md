# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

> **Kilo workspace note:** every code change made by Kilo in this workspace
> appends a dated entry under the relevant section below (create the section if
> it does not exist). Keep entries brief: one line per changed behaviour, grouped
> under `Added`, `Changed`, `Removed`, `Fixed`, and `Security`.

The changelog is split into per-day files in the `changelog/` directory.
The top-level file keeps the latest 5 dated entries; older entries live in
`changelog/<date>.md`. See `AGENTS.md` for the full discipline.

## [Unreleased]

> Last updated: 2026-09-26

### Added

- 2026-09-26: Shared layout component library on the iikiti front-end framework
  (`assets/js/iikiti/components/`): banner/header/footer/sidebar/panel with
  `StickyController` (static / sticky / absolute positioning; scroll, edge,
  hover-target and button triggers) and dynamic content via `setContent()` /
  `refresh()` / `iikiti.components` pub-sub. Wired onto `window.iikiti`.
- 2026-09-26: Admin dark theme with sun/moon toggle in the header
  (localStorage-persisted, OS-preference default, no FOUC via inline
  `layout.twig` script) using the 2027 color palette
  (Common Ground / Deep Rooted / Cottage Door / Grounded).
- 2026-09-26: `Icon` Svelte component (`@lucide/svelte`) exported from the
  `@iikiti/admin` barrel; menu icons now render as SVGs instead of raw text.
- 2026-09-26: Admin user menu with logout link and responsive mobile sidebar
  drawer (overlay + `aria-expanded` toggle) in `AdminLayout`.
- 2026-09-26: Admin defaults to the Dashboard route (`/` redirects to the
  first menu entry) and the Dashboard sorts first in the sidebar.
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

- 2026-09-26: Admin restyled with Tailwind CSS v4 (`@import "tailwindcss"` +
  `@theme` palette tokens + `@custom-variant dark`); all `admin-*` component
  classes are now defined in `assets/styles/admin.css` (`@layer components`)
  and every admin component/route uses semantic palette tokens.
- 2026-09-26: `AdminMenuItem` Dashboard priority raised to 1000 so it sorts
  first under the registry's descending-priority ordering.

### Fixed

- 2026-09-26: Admin SPA never loaded — `templates/base/layout.twig` had no
  `head_js` block, so the admin entry scripts were silently dropped.
- 2026-09-26: Admin API responses returned only `@id`/`@type` because
  `normalizationContext: ['groups' => …]` was set without matching serializer
  groups on resource properties (`UserResource`, `RoleResource`,
  `AuditLogResource`, `TemplateResource`, `SearchActionResource`); lists now
  serialize their fields.
- 2026-09-26: Admin API client missed API Platform 4's `member` collection key
  (`ApiClient::parsePaged()`/`extractItems()`), which rendered empty tables.
- 2026-09-26: Editor toolbar/sidebar `a11y` lint warnings (replaced the
  non-interactive `<nav role="toolbar">` with a `<div role="toolbar">`, gave the
  block preview `<div>` a keyboard toggle + `aria-label`, added an iframe
  `title`, and removed the unused `.iikti-editor__canvas` selector).
- 2026-09-26: Sidebar title strip used the light `admin-header` class against a
  light sidebar text color — moved to a dedicated `admin-sidebar-header`
  (kept on `var(--sidebar)`) so the title is readable in both themes.
- 2026-09-26: Removed the `edge` (mouse-proximity) hide trigger from the
  sidebar so it no longer collapses when the cursor leaves the left edge;
  sidebar now hides only on scroll-down and reveals on scroll-up / toggle.
- 2026-09-23: Front-end block editor backend: `BlockType` registry + core blocks
  (container, dynamic, heading/text, image, video_embed, social_embed, query),
  `BlockRenderer` (Twig, editor-metadata gating), embed resolver (YouTube/Vimeo +
  oEmbed social providers) and a safe, parameterized query executor
  (`src/Web/BlockEditor/`).
- 2026-09-23: `Template` entity + `TemplateRuleInterface` (object/objectType/site rules)
  + `TemplateResolver` + `TemplateRenderer` (region validation — a `main` content
  region is required or an error is shown) + `PageRendering` live-page pipeline;
  `HomeController` and `/{slug}` object-page route render through the pipeline.
- 2026-09-23: Editor API (`/api/editor/context`, `save`, `publish`, `render-block`)
  and a WebSocket room-admission gate; draft/publish save workflow with ETag
  concurrency; cache-backed presence store.
- 2026-09-23: `iikiti` JS framework entry (dependency+version-aware loader with
  `domReady`/`onLoad` promises, notifications, plugin auto-loading) and the
  `editor` Svelte 5 (runes) entry that mounts on `?edit` for authorised editors.
- 2026-09-23: Plugin manifest `editor_ui`/`site_ui` keys +
  `PluginRegistry::getManifests()`.
- 2026-09-23: `base/layout.twig` loads the `iikiti` framework on all pages and
  the editor chunk only in edit mode; editor bootstrap config is injected via
  `FrontendConfigProvider` only when the user can edit.
- 2026-09-23: Front-end workflow engine: `WorkflowSchemaExtractor` (FormType →
  JSON field schema), `/flow/{flow}/step` step-schema API, and a dynamic
  `Workflow.svelte` component loaded by iikiti when a `data-flow` marker is
  present (progressive enhancement of MFA / multi-step forms).
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

- 2026-09-26: Database query-cache profiler diagnostics moved out of the
  standalone "Database cache" panel and into Symfony's built-in Cache panel.
  `DatabaseCacheDataCollector` is now a tabless data collector (no `template`
  tag) rendered via an overridden `@WebProfiler/Collector/cache.html.twig` that
  appends strategy/generation/registered-strategy info; Symfony's pool stats are
  no longer duplicated. `DatabaseCacheManager::getPool()`/`getPoolStats()` were
  removed as redundant with the profiler's per-request `TraceableAdapter` stats.
- 2026-09-26: Changelog split into per-day files under `changelog/`; the top-level
  `CHANGELOG.md` now retains only the latest 5 dated sections, older sections are
  archived in `changelog/<date>.md`.
- 2026-09-26: Documentation for wildcard role hierarchy, `debug:roles` command,
  and dynamic role naming conventions added to `docs/roles-and-acls.md`.
- 2026-09-22: Symfony dependency baseline upgraded from 8.1 to 8.2
  (`composer.json` and all `symfony/*` constraints).
- 2026-09-22: `PermissionChecker` now injects `RoleHierarchyInterface` and
  expands user roles via `getReachableRoleNames()` before permission resolution,
  ensuring inherited and wildcard-matched roles are checked consistently.
- 2026-09-22: `RoleProcessor::delete()` now schedules a container rebuild via
  `PluginContainerRebuilder` after removing a custom `Role` entity, so the
  compiled role hierarchy stays in sync with DB state.
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
- 2026-09-19: `ObjectRepository` constructor now accepts an optional `SearchService`
  dependency; all 6 concrete repositories updated to pass it through.
- 2026-09-19: `FullTextSearch` service stub deprecated; replaced by
  `SearchIndexListener` in `src/Search/Listener/`.

### Deprecated

- 2026-09-19: `AdminExtensionInterface::getResources()` — use `getAdminScreens()`
  instead, which provides richer screen descriptors including component type, API
  path, and column/field config.

### Fixed

- 2026-09-26: Logout via direct navigation to `/logout` no longer rejects with a
  "no CSRF token" error — CSRF protection disabled on the logout endpoint in
  `config/packages/security.yaml`; the firewall's `LogoutListener` no longer
  requires a `_token` query parameter.
- 2026-09-26: Template rendering fixed — root cause was duplicate object IDs in
  the `objects` table.
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
- 2026-09-23: `SelectInput` Svelte 5 incompatibility (dynamic `multiple` with
  `bind:value`) — now uses static-`multiple` branches.
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

### Security

- (none in this entry window)

---

Entries dated before 2026-09-19 are archived in `changelog/2026-09-13.md`.
