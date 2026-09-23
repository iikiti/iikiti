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
- Toolbar: undo/redo, save draft, publish, viewport (mobile/tablet/desktop), view-live.
- Popovers: element tree, content/style inspector (schema-driven), template
  settings, view-live.
- Notifications: bottom-right stack, 10s auto-dismiss (configurable) or dismissible,
  scrollable/swipe on overflow. Settings via site defaults + user `preferences`.

## Plugin extensibility
- Block types via `BlockTypeInterface` (`iiketi.cms.block_type`).
- Manifest `editor_ui`/`site_ui`; editor plugin API `iikati.plugins.register(...)`.

## Follow-ups
- Default template seeding migration (built-in fallback used for now).
- Admin Templates CRUD screen (API resource + provider).
- Tiptap + `y-prosemirror` real-time rich-text adapter (contenteditable fallback in v1).
- MFA multistep fetch-submission branch.
