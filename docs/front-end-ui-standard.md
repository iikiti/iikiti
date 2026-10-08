# Front-end UI standard: viewport bars, layers & floating chrome

This document defines the shared front-end UI primitives every theme, plugin
and the core editor UI must use so that viewport chrome **stacks without
covering anything** and floats in a predictable order. Everything here is
available on **both the public site and the admin SPA** — the framework JS
(`assets/js/iikiti/`) and the shared primitives stylesheet
(`assets/styles/ui.css`) load in both contexts.

## Viewport bars (the "bars" standard)

A **bar** is any chrome element attached to one side of the viewport: the
editor toolbar, theme headers, thin "pencil" banners, footers, side rails,
plugin dock strips, etc.

The standard guarantees:

- Bars **never cover each other** or the page content.
- Bars on the **top/bottom** are part of the document flow: they *push* the
  content down/up. Optional `sticky` bars stay pinned to the viewport while
  the page scrolls (the manager computes cumulative sticky offsets for each
  bar from the sizes of the bars closer to the edge).
- Bars on the **left/right** ("rails") are fixed elements spanning vertically
  *between* the top and bottom stacks; they reserve horizontal space through
  `body` padding so content moves beside them.
- Elements explicitly designated `fixed` or `absolute` are the only
  exemption: they are left untouched and unmeasured (overlay chrome).
- Bars can opt into **drag-to-resize** (a handle appears on the edge facing
  the content; the size persists in localStorage per bar id). The editor's
  top admin bar intentionally never enables this.

### Registration

**Declarative** (theme/plugin server-rendered markup):

```html
<div data-iikiti-bar="top"                 <!-- top|right|bottom|left; required -->
     data-iikiti-bar-order="0"             <!-- lower = closer to the viewport edge -->
     data-iikiti-bar-sticky="true"          <!-- pin while scrolling (top/bottom) -->
     data-iikiti-bar-resizable="true"       <!-- adds the drag-to-resize handle -->
     data-iikiti-bar-mode="stack"           <!-- stack (default) | fixed | absolute -->
     data-iikiti-bar-size="240"             <!-- rails: explicit initial width (px) -->
     data-iikiti-bar-min-size="36"
     data-iikiti-bar-max-size="240"
     data-iikiti-bar-id="my-theme-banner">  <!-- stable id for size persistence -->
  …pencil ad content…
</div>
```

Elements with `data-iikiti-bar` are auto-wired on `domReady` and on
`iikiti:init` events — no JS needed. To register markup injected later,
dispatch `document.dispatchEvent(new CustomEvent('iikiti:init', { detail: { root } }))`
(the same contract the component registry uses).

**Imperative** (plugin `site_ui` bundles, the editor):

```js
const reg = iikiti.bars.register(el, {
  id: 'my-plugin-bar',          // optional; auto-generated otherwise
  side: 'top',                  // top|right|bottom|left
  order: 0,                     // stacking; editor toolbar uses -100
  sticky: true,                 // pin while scrolling
  resizable: false,
  mode: 'stack',               // 'fixed' | 'absolute' = overlay exemption
  size: 240,                   // rails: explicit width
  minSize: 36, maxSize: 240,
});
reg.destroy();                 // unregister; offsets re-layout

iikiti.bars.getOffset('top');   // px reserved by "stack" bars on a side
iikiti.bars.onChange(({ sides }) => { ... }); // re-layout notifications
iikiti.bars.autoWire(root);    // scan a subtree for data-iikiti-bar
```

Passing `null` as the element creates a **slot** — a manager-owned
`div.iikiti-bar` already positioned in the correct stack slot, which a
framework (e.g. Svelte `mount(component, { target: slot })`) can mount into.
That is exactly how the editor toolbar mounts.

### Resulting geometry

The manager maintains these CSS variables on `:root` at all times:

| Variable | Meaning |
|---|---|
| `--iikiti-bars-top` | px height of all "stack" bars on the top (0 if none) |
| `--iikiti-bars-bottom` | px height of bottom stack bars **plus external bottom chrome** (e.g. the Symfony web profiler toolbar) |
| `--iikiti-bars-left` / `-right` | px widths of all rail bars (0 if none) |

`ui.css` applies the left/right values as `body` padding-inline so rails push
content, and every bottom-anchored notification offsets itself by
`--iikiti-bars-bottom` so it never sits under the web profiler toolbar.
Top/bottom bars push content via normal flow **and span the full viewport
width**: the manager cancels the rail reserve with negative inline margins so
a top header always runs across the rails (the rails are pinned between the
top and bottom stacks, i.e. below the header).

### Rules & known constraints

- **Order semantics:** within a side, bars stack by `order` ascending, then
  registration sequence — *lower order = closer to the viewport edge*. The
  editor toolbar registers at `-100`, theme pencil banners/banner components
  default to `0`, headers to `1`, footers to `0`.
- **Stack bars are re-slotted as direct `<body>` children** (in stack order).
  `position: sticky` only pins within the parent's box, so this is required
  for viewport-wide pinning from any theme markup position. All classes are
  kept; only the ancestors change — avoid styling bars via ancestor
  selectors.
- Do not place bars under ancestors with `transform` or put
  `overflow: hidden` on `html`/`body` — both break sticky/fixed pinning.
  Rails are re-parented to `<body>` automatically, so only the `fixed` /
  `absolute` exemption can stay nested.
- **Layout components integrate:** `data-component="banner|header|footer"`
  register automatically (banner top order 0, header top order 1, footer
  bottom). `data-component="sidebar"` registers as a rail **only** when the
  markup opts in with `data-iikiti-bar="left|right"` (in-flow/flex sidebars —
  e.g. the admin SPA — keep their own positioning). `data-position="sticky"`
  components pin via the standard; `data-position="absolute"` stays exempt.
- Rails need an explicit width (`data-iikiti-bar-size`, inline width, or the
  persisted resizable size); auto-width fixed elements would be circular.
  Default rail width is 220px.
- A bar that hides (e.g. banner dismiss / sticky hide-reveal transforms)
  drops out of the stack automatically (size becomes 0, offsets recompute via
  ResizeObserver).
- Resizable bars persist to `iikiti.bar.<id>.size` in localStorage.
- **Symfony web profiler toolbar (dev only):** detected automatically. Its
  wrapper is kept at the end of the document flow so its static "clearer"
  reserves space at the page bottom (instead of mid-flow, which pushes content
  down at the top), and its fixed bar's height is counted into
  `--iikiti-bars-bottom`, so bottom bars, rails and notifications stack above
  it. Our chrome's z-indexes (`--iikiti-z-*`, max 4500) stay below the
  profiler's `z-index: 99999`, so the bar API never covers it. Open/collapsed
  state is observed and recomputed automatically.

## Layer (z-index) scale

All front-end chrome, editor and admin elements must position themselves via
the layer scale declared in `ui.css` (`:root`). Never write a raw integer
`z-index` in a component; use the token. The scale is grouped into bands
(ascending):

| Band | Range | Token | Value | Use for |
|---|---|---|---|---|
| Content | 0–99 | `--iikiti-z-content` | 0 | blocks, cards, page content |
| Content | 0–99 | `--iikiti-z-content-raised` | 10 | content items that must sit above siblings |
| Content | 0–99 | `--iikiti-z-content-overlay` | 20 | in-content badges, outlines, resize handles |
| Editor | 100–499 | `--iikiti-z-editor-canvas` | 100 | editor overlays on the canvas (edit trigger, locked badge) |
| Editor | 100–499 | `--iikiti-z-editor-controls` | 200 | insert buttons, context menus, field decorators |
| Editor | 100–499 | `--iikiti-z-editor-panel` | 300 | editor side panels (settings sidebar) |
| Shell | 500–999 | `--iikiti-z-shell-sidebar` | 500 | site/admin sidebars (mobile drawer) |
| Shell | 500–999 | `--iikiti-z-shell-overlay` | 600 | scrim behind an open sidebar |
| Shell | 500–999 | `--iikiti-z-shell-header` | 700 | sticky page headers inside the shell |
| Chrome | 1000–1999 | `--iikiti-z-chrome` | 1000 | viewport bars (sticky/fixed site chrome, editor toolbar) |
| Overlay | 2000–4999 | `--iikiti-z-popover` | 2000 | popovers, menus, palettes, inspector |
| Overlay | 2000–4999 | `--iikiti-z-floating` | 3000 | draggable floating panels |
| Overlay | 2000–4999 | `--iikiti-z-modal` | 3500 | modal dialogs + backdrops |
| Overlay | 2000–4999 | `--iikiti-z-tour` | 3900 | guided tour spotlight (below its own tooltips) |
| Overlay | 2000–4999 | `--iikiti-z-tooltip` | 4000 | tooltips |
| Overlay | 2000–4999 | `--iikiti-z-toast` | 4500 | notifications |

Reserved: 9000+ is for debug tooling (the Symfony profiler uses 99999). Do
not use it in application code.

Rules:

- Extend the scale in `ui.css` (and this table) when a genuine new layer is
  needed; never fork it in a component.
- Local layering inside a component's own stacking context (e.g. a handle
  inside a bar) may use a band token from the content band; prefer
  `isolation: isolate` on the component root to scope it.
- Tooltips use `--iikiti-z-tooltip`. `Tooltip.svelte` portals itself to
  `document.body`, so a tip raised from a sticky toolbar is never trapped in
  that toolbar's stacking context.
- JS code reads a layer value via `assets/js/iikiti/chrome/layers.js`
  (`layerValue('tooltip')`) instead of hardcoding the number.

Floating panels bring themselves to front within the floating layer on pointer
interaction.

## Theming

All standard chrome (bars, floating panels, tooltips, toasts, popovers) is
coloured exclusively through the shared `--ik-*` component tokens
(`--ik-panel-bg/border/text/-muted`, `--ik-accent(-hover)`, `--ik-radius`),
declared in `app.css` with `.dark` overrides. The `.dark` class is applied
to `<html>` **before first paint** by an inline bootstrap script in
`layout.twig` (stored `theme` localStorage preference, falling back to the OS
preference) — the same strategy the admin SPA uses. The editor toolbar
exposes the toggle (`iikiti.theme.toggle()`); themes/plugins that add bars
or panels must use the same tokens so they flip with the site theme
automatically — never hard-code colours in chrome.

## Shared Svelte components (plugins welcome)

Exported from `@iikiti/ui` (aka `$components`):

- **`Tooltip.svelte`** — wraps a trigger snippet; shows on hover/focus with
  a delay. Anchored to the **mouse cursor**: it appears below the pointer with
  a gap and always clears the hovered element, mirroring/clamping at the
  viewport edges; keyboard focus falls back to below the trigger. Use for
  icon-button affordances (with `aria-label` on the button).
- **`Dialog.svelte`** — the generic draggable dialog built on the native
  `<dialog>` element: header drag handle, viewport clamping, optional corner
  drag-resize, Esc-to-close, bring-to-front, optional `storageKey` persistence
  (`iikiti.panel.<key>.pos` / `.size`). Non-modal (`dialog.show()`),
  `aria-modal="false"`. `FloatingPanel` is re-exported from `@iikiti/ui` as a
  **deprecated alias** of `Dialog` for plugin backward compatibility.
- **`ModalDialog.svelte`** — extends `Dialog` with `modal=true`: calls
  `dialog.showModal()`, moving it into the browser top layer with a
  `::backdrop` (`--iikiti-z-modal`) that covers the rest of the site.
  Centred, not draggable/resizable.
- **`Popover.svelte`, `Toast.svelte`, `NotificationCenter.svelte`, `Icon.svelte`**
  — pre-existing primitives, now styled by the shared `ui.css` on front-end
  pages too (previously popover/toast CSS only existed in the admin bundle).

The underlying drag-resize logic is framework-agnostic:
`assets/js/iikiti/chrome/dragResize.js` (`bindResize`, `createResizeHandle`,
`readPersistedSize`) — reusable by vanilla-JS plugin bundles.
