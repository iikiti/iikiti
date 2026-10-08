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

> Last updated: 2026-10-07

### Added

- 2026-10-08: `iikiti\CMS\Value\TimeZone` value object: validated IANA identifier that yields an `IntlTimeZone` when `intl` is loaded, otherwise a `DateTimeZone`. Stored as text; reusable for any zone, not only users.
- 2026-10-08: Users can save an IANA time zone (`POST /api/user/timezone`, stored in preferences as `timezone`, validated by `User::setTimeZone()`). The admin bootstrap (`data-time-zone`) and front-end config (`timezone`) carry it, and `userTimeZone()` uses it before the browser's zone.
- 2026-10-08: `DateTime` admin component and `formatDateTime()` (`assets/js/iikiti/time/temporal.js`) show ISO instants in the user's time zone with a definer-chosen `kind` (`date`/`time`/`datetime`) and `style`; `@js-temporal/polyfill` is loaded on demand only when native `Temporal` is missing.
- 2026-10-08: `iikiti.loader.loadIf(probe, { url, read, strategy? })` resolves to the native value when `probe()` returns one, otherwise loads the polyfill once and resolves to `read()`; concurrent callers share one load.
- 2026-10-08: `PluginAuditRecorder` and `PluginEvent::recordChange()` so plugin lifecycle listeners attribute audit changes to the plugin slug and version; plugin install/enable/disable/update/remove transitions are recorded automatically (`PluginLifecycleHandler`).
- 2026-10-08: Audit log now records human-readable top-level events (`summary`) with technical sub-events (`parent_id`); one event per request or CLI command, stored only when it changed something (`AuditContext`, `AuditRequestListener`, migration `Version20261008130000`).
- 2026-10-08: `AuditRecorder` public API for plugins and core code; every recorded change requires a non-empty summary (`docs/plugin-api.md`).
- 2026-10-08: Login and logout are audited as their own top-level events; API token creation during login is a sub-event (`SecurityAuditSubscriber`).
- 2026-10-08: Audit entries store the initiator's `username_snapshot`; the viewer resolves the live username and falls back to the snapshot.
- 2026-10-08: Audit Log admin screen rows show the human summary and user; clicking a row opens a detail dialog with the initiator (id and username), time, action and all sub-event steps.
- 2026-10-08: Session-authenticated shell endpoints `/admin/layouts/shells` (`LayoutController`,
  CSRF on writes) for the editor Layout dialog. The token-only `/api/admin/shells` stays for
  external management.
- 2026-10-08: Global layout shells as a new `Shell` object type (`src/Entity/Object/Shell.php`),
  with admin API `/api/admin/shells` (`ShellResource`, `ShellProcessor`, `ShellStateProvider`)
  and a Layouts admin screen. Shells are validated on save by `ShellValidator` (container-only
  root blocks, registered display rules, known role).
- 2026-10-08: Editor Layout toolbar button and `LayoutDialog` to list shells by role and add,
  edit, enable or delete them.
- 2026-10-08: Editor UI text is at least 1rem (16px): sub-16px `font-size` rules in editor components raised, and `editor.css` sets a `max(1rem, 1em)` floor on editor roots and text-bearing controls.
- 2026-10-08: Editor controls enlarged for easier use (toolbar 40px, canvas add/context buttons 36px, sidebar rows 36px). On coarse pointers or no-hover devices, interactive editor controls use a 48px minimum touch target (`editor.css`).
- 2026-10-08: Before/after insert plus buttons on hovered blocks are centred horizontally (`left: 50%`) and sit fully outside the block: "before" above the top edge, "after" below the bottom edge. Invisible 40px hover zones above and below each block keep the buttons reachable when the pointer moves onto them.
- 2026-10-08: Child-accepting blocks (container, query, heading) render a dashed "Add child" slot in
  the editor canvas so children can be added without the hidden block context menu.

### Fixed

- 2026-10-08: Audit log no longer records plain navigation: GET/HEAD/OPTIONS requests open no event; state-changing requests and login/logout are unchanged.
- 2026-10-08: Audit entry detail page has padding and renders the timestamp through `DateTime`.
- 2026-10-08: Logout raised "A new entity was found through the relationship AuditLogEntry#parent": sub-events are now persisted only after their parent, and the parent is persisted first on close.
- 2026-10-08: Login/logout recorded a generic `request` parent with the named event nested inside it; the named event now replaces the generic request event, keeping buffered changes.
- 2026-10-08: Audit entries recorded without a summary log a warning naming the responsible caller and use a generated fallback; create/update entries from the Doctrine subscriber now carry real summaries.
- 2026-10-08: Audit log entries were no longer written as `created`/`deleted` rows without context for ObjectProperty deletions; deletions now include the property name and value.
- 2026-10-08: `Tooltip.svelte` renders its tip through a `document.body` portal so editor header tooltips no longer appear beneath the settings sidebar.
- 2026-10-08: `Tooltip.svelte` accepts a `zIndex` prop (default `--iikiti-z-tooltip`) so the stacking order can be overridden per instance.
- 2026-10-08: `FullTextSearch::postPersist` now accepts `(entity, args)` as Doctrine calls it; every
  `DbObject` persist previously threw a TypeError.
- 2026-10-08: `DbObject` gains `setSite`, `setCreatorId` and `setCreatedDate`; new objects set their
  NOT NULL owner and creation date.

### Changed

- 2026-10-08: `loadIf` now takes a probe and polyfill spec and resolves to the value (native or polyfill reference) instead of a boolean; the boolean/expression-string condition form and `resolveCondition` are removed. `ensureTemporal()` uses it.
- 2026-10-08: Shared Svelte components, lib and types moved from `assets/svelte/admin/` to `assets/svelte/shared/` (`components`, `lib`, `types`) so the admin, editor and front-end use one set; aliases `$components`, `$lib`, `$types`, `$iikiti` and `@iikiti/ui` (formerly `@iikiti/admin`, kept as an alias) point there. `$routes` stays admin-only.
- 2026-10-08: `AuditLogger` writes sub-events under the open request event; `AuditSubscriber` records ObjectProperty deletions with name/value and readable summaries.
- 2026-10-08: `ApiTokenManager` records only real API token creation and expired-token removal as audit sub-events (not every page load).
- 2026-10-08: Z-index standard: the `--iikiti-z-*` scale in `ui.css` is now banded (content 0-99, editor 100-499, shell 500-999, chrome 1000-1999, overlay 2000-4999; debug 9000+ reserved). Editor, shell, tour and admin components use tokens instead of raw integers, and `assets/js/iikiti/chrome/layers.js` exposes `layerValue()` for JS. The editor settings side panel moves from the chrome band to the editor-panel band.
- 2026-10-08: Header, footer, sidebars and dialogs are now shells (`Template::getShells`)
  rendered per role only when a shell's display rules match (`ShellResolver`, reusing the
  `iikiti.cms.template_rule` registry). Empty shell roles emit no markup. Only `main` is a
  fixed region.
- 2026-10-08: Empty main content region shows a dashed box with a centered "+" that opens
  the add-block dialog; empty non-main regions no longer show placeholder text.
- 2026-10-08: Only `container` blocks may sit at the root of a region. Enforced on
  save (`RootContainerRule` → `DraftPublishWorkflow::save` returns a violation
  error), public rendering (`BlockRenderer::renderTree` skips root non-containers),
  and the editor (`addBlock`/`moveBlock` in `state.js` and the Add block dialog
  offer only Container at root).

### Removed

- 2026-10-08: Migration `Version20261008120000` converts existing non-main region blocks into
  global shells (sites/creators are copied from the template; templates lacking them are skipped).
- 2026-10-08: Cleared all stored block trees (`blocks`, `blocks_draft`) via
  migration `Version20261008000000` so regions render empty.

### Added

- 2026-10-07: Circular "+" insertion buttons before and after every block in
  the editor canvas (revealed on hover/tap, compact variant inside headings);
  each opens the shared Add block dialog at that exact gap.
- 2026-10-07: Shared modal "Add block" dialog (`AddBlockDialog.svelte`) with
  search and category grouping, replacing the anchored `BlockPalette` popover
  for every entry point: canvas pluses, the new toolbar "+" (appends to the
  active region end), the region-level add button, and "Add child…" (now shown
  for any block whose type accepts children, filtered to its allowed child
  types). Inserting selects the new block.
- 2026-10-07: `inline_text` core block type — a basic text node optionally
  wrapped in a non-block element (`span|em|strong|b|u|i|small|code|mark`,
  allowlist-enforced server-side) — and the `heading` block reworked into a
  header container that accepts only `inline_text` children (no wysiwyg).
  `BlockType` gained a `wrapperTag` (inline types render as `span` so markup
  stays valid inside `<h2>`…).
- 2026-10-07: Query blocks are per-result templates: the renderer executes the
  query and renders the stored child list once per result row, exposing the row
  to templates as `item`; `query.twig` falls back to running the query itself
  for standalone renders. The canvas preview shows children once with a
  "repeats per result" hint.
- 2026-10-07: Dynamic field bindings: each block carries optional `bindings`
  (`<fieldKey> → <spec>`, hydrated via `data-block-bindings`), resolved per
  query result by `QueryFieldCatalog` (whitelisted columns, whitelisted/
  arbitrary object properties, related-entity scalar fields, plugin functions);
  resolved values are markup-stripped before templates escape them.
- 2026-10-07: Sidebar field decorator API
  (`window.iikiti.editor.sidebar.registerFieldDecorator`) with a core
  "query binding" decorator: a database icon in the corner of any sidebar field
  (hover reveal, long-press on touch) opening a picker of mappable fields
  served by the new `GET /api/editor/query-fields` endpoint.
- 2026-10-07: `QueryFieldFunctionInterface` (`iikiti.cms.query_field` tag) for
  plugins to register dynamically generated query result fields.
- 2026-10-03: Editor settings sidebar (docked via the viewport bars standard,
  default left, flippable left/right/top/bottom, resizable) rendering
  Content / Element / Style tabs for the selected block.
- 2026-10-03: Plugin-extensible editor sidebar API
  (`window.iikiti.editor.sidebar`): ordered sections (tabs), field-control
  registry (`field.type` → Svelte control), section patch transforms, and
  programmatic tab switching; the core Content/Element/Style tabs and built-in
  field controls register through the same API. Plugin `editor_ui` entries
  (`config.plugins`) are now loaded by the editor and can call it.
- 2026-10-03: `elementFields` schema on `BlockType` (baseline `id` +
  `cssClass` for core types, `data-block-element` hydration attribute) with a
  built-in "Attributes" group/repeater whose `{name,value}` rows render as
  wrapper HTML attributes through an allowlist (deny `on*`/`style` and
  `javascript:`/`data:` values).
- 2026-10-03: Block-widget dashboard foundation: block types carry a `source`
  provider slug; `GET /api/admin/block-widgets` enumerates registered widgets
  and `GET /api/admin/block-widgets/usage` returns per-type counts +
  `usedBy` template/object references via the on-demand
  `BlockUsageIndexInterface` (`BlockUsageIndexer`).
- 2026-10-03: Shared tour/spotlight framework (`assets/js/iikiti/tour.js`,
  `window.iikiti.tour`): tour registry, step popovers above/beside the target,
  spotlight highlighting via stable `data-tour` anchors, step actions, and a
  `TourPopover` component mounted in both the editor and admin SPA. The editor
  contributes `sidebar.tab` / `sidebar.side` / `open` actions.
- 2026-10-03: Editor chrome regions (`header`/`dialog`/`footer`/`sidebar`,
  incl. a new `dialog` region role) render locked/read-only: clicking one
  switches the active region (with "Back to content"); the Layers dialog and
  canvas edit only the active region (default `main` content).
- 2026-10-03: Structure popover (`StructureMenu.svelte`, repurposed from the
  old content/style inspector popover): breadcrumb path + move up/down for
  quickly reordering the selected block among siblings.

### Changed

- 2026-10-03: Editor sidebar restyled to match the top bar (same translucent
  panel background + blur, token colours, button metrics) and the top bar now
  spans the full viewport width with the docked sidebar rail below it (top/
  bottom stack bars cancel the rail reserve instead of letting the body
  padding inset them).
- 2026-10-03: `FloatingPanel` renamed to `Dialog` and rebuilt on the native
  `<dialog>` element (non-modal `show()`, draggable/resizeable, persisted
  position); `ModalDialog` added (`showModal()` + `::backdrop`, covers the
  site). `FloatingPanel` remains as a deprecated alias export; the old
  Tailwind `Dialog.svelte` was superseded.

### Removed

- 2026-10-07: `BlockPalette.svelte` anchored palette popover (superseded by the
  shared modal Add block dialog used by every add-block entry point).
- 2026-10-03: Structure popover on the selected block (move up/down buttons):
  redundant with the Layers-panel drag handle and canvas drag/drop.

### Fixed

- 2026-10-07: Review fixes for dynamic field bindings: sensitive accessors
  (password/secret/token/credential/MFA/identifier-style getters) are no longer
  resolvable or offered in the binding picker; only text-like content fields
  accept bound values (select fields such as heading `level` are never
  overlaid); the sidebar binding decorator applies to content fields only.
- 2026-10-07: Query blocks render safely under failure and nesting: a failing
  query degrades to the empty state instead of failing the page, nested query
  blocks fall back to legacy rendering, and self-referencing trees render a
  placeholder. The insert dialog no longer offers a query inside a query.
- 2026-10-07: Editor drafts saved while a query block has no rows keep their
  child template (editor-only hydration marker); heading level is clamped to
  H2–H6; the inline-tag allowlist is single-sourced from
  `CoreBlockTypeProvider::INLINE_TAGS`.
- 2026-10-07: Editor save always returned 409 (conflict) after the first save:
  the bootstrap config did not carry the template's current `draft_version`,
  so every save sent a stale `If-Match`; `FrontendConfigProvider::build()` now
  includes the version (passed from `PageRendering`).
- 2026-10-07: Editor hydration lost/duplicated nested children across saves:
  the parser deep-scanned `[data-block-children]`, so a `query` block absorbed
  a nested container's children wrapper and blocks with inline children
  (`heading` → `inline_text`) dropped them; hydration now uses a dedicated
  first-item marker (`data-block-item-children`) for query templates, and the
  heading template emits its own `data-block-children`.
- 2026-10-03: Editor save/publish endpoints returned 403 for page editors:
  `/api/editor/save` now requires the `write` permission (instead of a
  non-existent `save` action) and `Template`/`Page` write is accepted in
  template context, mirroring the editor bootstrap gate.
- 2026-10-03: Block selection via canvas click/✏ threw
  `TypeError: ...set is not a function` (derived store used as writable);
  selection goes through the exported `select()`.

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
- 2026-09-29: Front-end block editor add-block UI — `BlockPalette.svelte` component
  that shows available block types (filtered by region/container `allowed_types`)
  and inserts a default block node into the tree. `Region.svelte` renders an
  “Add block” button (hover/tap to reveal); `BlockView.svelte` adds an “Add child”
  context-menu option for container blocks.
- 2026-09-29: Editor `state.js` tree operations — `addBlock`, `deleteBlock`,
  `moveBlock`, `searchNode`, `allowedChildTypes`, `defaultContentFor` exports;
  `window.__iikitiSearch` wired in `init()` so `Inspector.svelte` can resolve
  the selected node.
- 2026-09-29: `editor.css` webpack style entry — edit-trigger hover/touch reveal,
  block hover/active outlines, context-menu and block-palette styles.
- 2026-09-29: Touch device detection in `iikiti/index.js` — adds a `iikiti-touch`
  body class so CSS can show edit icons persistently (no hover dependency).
- 2026-10-03: Viewport-bars stacking standard (`iikiti.bars`, 
  `assets/js/iikiti/chrome/bars.js`): declarative `data-iikiti-bar` markup +
  `iikiti.bars.register()/unregister()/getOffset()/onChange()` on every page;
  top/bottom bars stack in flow with cumulative sticky offsets, left/right
  rails are fixed spans reserving body padding, `fixed`/`absolute` overlay
  exemption, optional drag-to-resize with localStorage persistence
  (`dragResize.js`), per-side totals as `--iikiti-bars-*` CSS vars.
  Available to the editor, themes and plugins — see
  `docs/front-end-ui-standard.md`.
- 2026-10-03: Shared z-index layer scale + UI primitives stylesheet
  (`assets/styles/ui.css`, imported by both `app.css` and `admin.css`):
  `--iikiti-z-chrome/popover/floating/modal/tooltip/toast` tokens, bar/resize
  handles, popover/toast/notification-center styling for front-end pages,
  tooltip + floating-panel primitives.
- 2026-10-03: `Tooltip` (hover/focus, viewport-flipping, keyboard-safe) and
  `FloatingPanel` (generic draggable + resizable dialog with bring-to-front
  and position persistence) Svelte components, exported from
  `@iikiti/admin`.
- 2026-10-03: Editor "Layers" menu (`LayerMenu.svelte`) — floating draggable
  block-tree navigator: regions → nested blocks with counts, click selects a
  block + scrolls the canvas to it, selection highlighted, opened from the
  toolbar, position/size persisted per browser.
- 2026-10-03: New icons in the shared `Icon` map: `undo-2`, `redo-2`,
  `rocket`, `external-link`, `box`, `heading`, `image`, `video`, `link`.
- 2026-10-03: Dark/light theme on the editor bar: sun/moon toggle wired to
  the front-end theme API (`iikiti.theme.toggle()`, localStorage-persisted).
  Front-end dark mode is now class-based like the admin's — the FOUC theme
  bootstrap script moved into `templates/base/layout.twig` `head_styles`
  (stored preference, else OS preference; admin inherits via `parent()`),
  Tailwind `dark:` utilities follow `.dark` (`@custom-variant dark` in
  `app.css`) and `app-dark.css` extras are `.dark`-scoped.

### Changed

- 2026-09-26: Admin restyled with Tailwind CSS v4 (`@import "tailwindcss"` +
  `@theme` palette tokens + `@custom-variant dark`); all `admin-*` component
  classes are now defined in `assets/styles/admin.css` (`@layer components`)
  and every admin component/route uses semantic palette tokens.
- 2026-09-26: `AdminMenuItem` Dashboard priority raised to 1000 so it sorts
   first under the registry's descending-priority ordering.
- 2026-09-29: `PageRendering::render()` builds the editor config whenever the user
  `canEdit` (not only when `?edit`); `BlockTwigExtension::region()` emits
  `data-component`/region attrs on `canEdit`; `layout.twig` loads `editor.css` and
  emits `iikiti-can-edit`; config included for any authorised editor, not just
  `?edit`; `editorMode` still gates `data-block-*` metadata.
- 2026-09-29: `iikiti/index.js` edit-trigger click navigates to `?edit` (full editor
  mode); touch device detection adds `iikiti-touch` body class.
- 2026-10-03: Editor top bar is now a bars-standard top bar (order -100,
  always pinned, not resizable): sticky at the viewport edge while pushing
  the page content down via document flow instead of covering it; Undo/Redo/
  Save draft/Publish replaced with Lucide icon buttons + hover tooltips
  (`Toolbar.svelte`), undo/redo disabled at the history floor and save shows
  an unsaved-changes dot (`canUndo`/`canRedo`/`dirty` store exports).
- 2026-10-03: Layout components participate in the bars standard: banner
  (top, order 0), header (top, order 1) and footer (bottom) register
  automatically; sidebar registers as a left/right rail only when the markup
  opts in with `data-iikiti-bar` (in-flow/flex sidebars like the admin SPA
  keep their own positioning).

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
- 2026-10-03: Front-end editor never launched in production builds —
  `launchEditor()` awaited a hardcoded unversioned `/build/editor.css` (404
  with hashed filenames), aborting before mounting; the stylesheet is already
  injected server-side by `layout.twig`, so the dynamic load was removed.
- 2026-10-03: Selecting any block crashed the editor with
  `ReferenceError: Popover is not defined` — `Inspector.svelte` used
  `<Popover>` without importing it.
- 2026-10-03: Inspector rendered with the wrong `FormField` API (`label` prop
  instead of `field`), crashing on `field.required`; both usages now pass the
  schema `field` object.
- 2026-10-03: Inspector closed itself when selecting blocks from anywhere but
  the anchored block (e.g. the Layers panel) — `closeOnOutside` disabled for
  the anchored inspector popover.
- 2026-10-03: Popover/toast/notification-center CSS only existed in the admin
  bundle, leaving front-end editor popovers unstyled — shared primitives moved
  to `assets/styles/ui.css` loaded by both themes.
- 2026-10-03: Component registry logged `unknown component
  "BlockEditorComponent"` warnings on every editor page — the editor's DOM
  hook is now skipped explicitly by the layout-component auto-wire.
- 2026-10-03: Editor chrome rendered light-only — nothing applied the
  `.dark` class on front-end pages (the old toolbar hard-coded its own
  `prefers-color-scheme` block); theme application is now shared via the
  layout bootstrap and all editor chrome themes through the `--ik-*` tokens.
- 2026-10-03: Tooltips and popovers were never actually CSS-positioned —
  Svelte 5 dropped object support for the `style={…}` attribute, so
  `Tooltip`/`Popover` rendered at their static position. Both now position
  via `style:` directives; tooltips anchor below the mouse cursor (clear of
  the hovered element, mirrored/clamped at viewport edges, below the trigger
  for keyboard focus) and the inspector popover repositions correctly.
- 2026-10-03: Review fixes on the new editor chrome: the inspector popover
  now repositions when the selection (anchor) changes; corner drag-resize
  respects per-axis maxima (85vh/95vw for panels instead of a width-based
  height cap); persisted sizes restore for all resizable bars, not just
  rails; re-layout skips redundant bar DOM moves and `onResize` forced
  reflows; the editor unregisters its toolbar bar on teardown; the
  notification overflow-scroll rule was restored; the front-end status
  palette is declared once in `app.css`; and the dead `.iikiti-btn--success`
  rule was removed.
- 2026-10-03: Viewport-bars standard now accommodates the Symfony web profiler
  toolbar: its wrapper is kept at the end of the document flow so the static
  "clearer" reserves space at the page bottom (it previously sat mid-flow and
  pushed the editor canvas down at the top), and its height is counted into
  `--iikiti-bars-bottom` so bottom bars, rails and notifications stack above
  it; collapse/expand is observed and recomputed. Our chrome stays below the
  profiler's z-index (99999), so it never covers the profiler bar.

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
