# Front-end block editor

Live-page block editor for iikiti, built with **Svelte 5 (runes)** and layered onto
server-rendered Twig output. Content is edited *on the live page*; the public page
is pure server-rendered HTML (no editor JS for visitors).

## Rendering model
- Each block is rendered server-side by a Twig template (`templates/blocks/*.twig`).
- Each block type declares a `renderTemplate` (Twig) and an `editorComponent` (Svelte).
- `PageRendering` resolves a `Template` (via `TemplateResolver`) and renders its
  regions with `TemplateRenderer` -> `BlockRenderer`.
- In **editor mode**, block wrappers carry `data-block-*` attributes so the editor
  hydrates the DOM. These attributes are **not** emitted for public visitors.

## Activation
- Loaded only when authorised (`?edit` + logged-in user with `Page:write` /
  `Template:write`). Unauthorized `?edit` renders the public page.
- `assets/js/iikti/index.js` (always on) reads `window.iikti.config`, shows a ✏
  edit icon on each region, auto-loads the editor on `?edit`, and auto-loads plugin
  `site_ui` bundles. Editor chunk is code-split / on demand for visitors.

## Block types (core)
container, dynamic, heading, text, image, video_embed, social_embed, query
(`src/Web/BlockEditor/BlockType/CoreBlockTypeProvider.php`).

## Templates
DbObject (type discriminator `iikiti\\CMS\Entity\Object\Template`)` with JSON properties:
- `layout` — Twig layout (default `base/default-block-editor-content.twig`).
- `regions` — `{id,name,role,allowed_types}` (a `main` region is required or an
  error is shown).
- `blocks` / `blocks_draft` — `{regionId:[blockNode,...]}`.
- `assignments` — `[{rule,config,priority}]` (extensible via `TemplateRuleInterface`).
- `settings` — template-level editor settings.

Rules (tagged `iikiti.cms.template_rule`): `object_type`, `object`, `site`.

## Saving / publishing
- Snapshot autosave to `*_draft`; ETag concurrency (`409` on stale version ->
  editor re-snapshots). `Publish` copies draft -> published. Public rendering
  always reads published.
- API: `POST /api/editor/save`, `POST /api/editor/publish`,
  `GET /api/editor/context`, `POST /api/editor/render-block`.

## Collaboration (realtime)
- Presence/cursors/locks via Yjs awareness over a WebSocket relay.
- In-scope transport: `y-websocket` Node sidecar (room-admission gate
  `GET /api/editor/room/{contextType}/{contextId}?token=`). Redis/Mercure are
  plugin territory (`CollaborationTransportInterface`). See
  `docs/realtime-collaboration.md`.

## Editor UI
- **Top bar** (sticky, never covers content): registered with the viewport
  [bars standard](front-end-ui-standard.md) as a top bar (order -100,
  always pinned, not resizable) and mounted into the manager-owned slot
  (`Toolbar.svelte`). The page content is pushed down by document flow while
  the bar stays pinned on scroll, and the bar spans the full viewport width —
  the docked sidebar rail sits below it. Undo/redo are disabled at the
  history floor; save shows an unsaved-changes dot; publish is gated by
  `canPublish`.
- **Settings sidebar**: docked via the bars standard (default left; flippable
  left/right/top/bottom with `⇄` button; width resizable) and styled to match
  the top bar (same translucent panel background + blur, `--ik-*` tokens and
  button metrics). Tabs: **Content**, **Element**, **Style**
  (schema-driven). The Element tab includes built-in `id`/`cssClass` fields
  and an **Attributes** repeater (name/value rows, add/remove) that renders
  onto the block wrapper.
- **Dark/light theme**: the bar and every floating panel follow the shared
  `--ik-*` tokens, which flip with the `.dark` class applied pre-paint by
  the theme bootstrap in `layout.twig` (stored preference, else OS
  preference). A sun/moon toggle on the bar exposes the front-end theme API
  (`iikiti.theme.toggle()`); the preference persists in localStorage under
  the `theme` key.
- **Icon actions + tooltips**: undo (`undo-2`), redo (`redo-2`), save draft
  (`save`, dirty dot), publish (`rocket`) — Lucide icons via the shared
  `Icon` component with hover/focus tooltips (`Tooltip` component).
- **Layers menu**: floating, draggable dialog (built on the native-`<dialog>`
  `Dialog.svelte`) listing the **active region** only (the page "content",
  i.e. the `main` region). Clicking a row selects the block, scrolls the
  canvas to it and highlights the current selection; position/size persist
  per browser.
- **Chrome regions (locked)**: `header`/`dialog`/`footer`/`sidebar` regions
  are rendered as locked chrome (read-only). Clicking a locked region makes
  it the active (editable) region; a "Back to content" button returns to
  `main`.
- **Block palette** popover (add block / add child), block context menus, and
  popovers/toasts styled by the shared `ui.css` primitives (also on front-end
  pages now).
- **Notifications**: bottom-right stack, 10s auto-dismiss (configurable) or
  dismissible, scrollable on overflow.

## Settings sidebar extension API
- The sidebar tabs, field controls, and field lists are all built through one
  plugin-shared API. Core registers them first; plugin `editor_ui` bundles
  register theirs after.
- `window.iikiti.editor.sidebar` exposes:
  - `registerSection({id,label,icon,order,build(ctx)})` — add a tab; `build`
    returns a node list from `{node, schema, blockTypes, update}`.
  - `registerFieldControl(type, Component, {priority})` — map a schema field
    `type` → a Svelte control `{field, value, onChange}`; higher priority wins
    (overridable).
  - `patchSection(sectionId, (nodes, ctx) => nodes, {priority})` — insert,
    remove or reorder any node in an existing tab.
  - `getSections()`, `buildSectionNodes(id, ctx)`, `setActiveSection(id)`.
- Node model: `{kind:'field', field, path}` | `{kind:'group', id, label,
  header?, nodes}` | `{kind:'repeater', id, fields, items, onChange,
  addLabel}`. Groups/repeaters compose (e.g. the built-in "Attributes"
  repeater lives inside an "Attributes" group in the Element tab).
- Field write paths: `path:'content'` → `node.content.<key>`, `path:'element'` →
  `node.element.<key>`, `path:'style'` → `node.style.base.<key>`. Plugins can
  override with a node-level `get(ctx)`/`set(ctx,v)`.
- `BlockType` gained an `elementFields` list (parallel to `contentFields`/
  `styleFields`) and a `source` provider slug, surfaced in `blockTypes` output
  (used by the editor and by the block-widget enumeration API).

## Tour / spotlight framework
- `window.iikiti.tour` (shared editor + admin SPA):
  `register(tour)`, `start(id)`, `next/prev/jumpTo/end`, `on(state=>)`,
  `registerAction(type, handler)`.
- Steps target `[data-tour="<id>"]` anchors or CSS selectors; show a popover
  above/beside the target with `placement` and optional spotlight (dim +
  outline). `before`/`after` action lists run on step enter/leave.
- Built-in actions: `highlight`, `scrollIntoView`, `emit`. The editor
  registers `sidebar.tab` (switches the active settings tab), `sidebar.side`
  (flips the sidebar), and `open` (e.g. `target:'layers'`). Plugins register
  their own via `registerAction`.
- `TourPopover.svelte` (exported via `@iikiti/admin`) renders the active step;
  mount it once in the editor (`Editor.svelte`) and in `AdminLayout.svelte`.
- Authoring actual tours is plugin work; the framework is in place for you.

## Plugin extensibility
- Block types via `BlockTypeInterface` (`iikiti.cms.block_type`), now with
  `elementFields` and a `source` slug.
- Manifest `editor_ui` entries are loaded by the editor on mount and may call
  the sidebar/tour APIs above.
- `iikiti.plugins.register(...)` for plugin front-end bundles.

## Follow-ups
- Default template seeding migration (built-in fallback used for now).
- Admin Templates CRUD screen (API resource + provider).
- Tiptap + `y-prosemirror` real-time rich-text adapter (contenteditable fallback in v1).
- MFA multistep fetch-submission branch.
