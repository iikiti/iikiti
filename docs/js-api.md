# Client-side JavaScript API (`window.iikiti`)

`window.iikiti` is the public browser API for themes, plugins and the editor.
It is created by `assets/js/iikiti/index.js`, which is loaded on every public
and admin page. This document covers only the public surface on
`window.iikiti`; internal modules under `assets/js/iikiti/` are not part of the
contract.

Stability: each member is tagged `stable` (safe to depend on) or
`experimental` (may change between releases).

## Load timing and readiness

Members are not all available at the same moment. Use `whenReady` rather than
polling or assuming availability.

```js
await window.iikiti.whenReady('iikiti:ready'); // base set is usable
const sidebar = await window.iikiti.whenReady('editor.sidebar'); // editor only
```

`whenReady(name)` returns a promise. It resolves once `name` is ready, including
when it became ready before the call. A name that never becomes ready (for
example `editor.sidebar` on a public page) stays **pending indefinitely**: there
is no built-in timeout or rejection. Add your own timeout if you need one.

| Name | Ready when | Stability |
|------|-----------|-----------|
| `components` | component registry is installed | stable |
| `bars` | viewport bars are wired on DOM ready | stable |
| `theme` | theme API is installed | stable |
| `notifications` | notification API is installed | stable |
| `plugins` | plugin start-up has finished (also set if a plugin fails) | stable |
| `tour` | tour framework is installed | stable |
| `iikiti:ready` | every base name above is ready | stable |
| `editor.sidebar` | front-end editor has installed its sidebar API | experimental |

`editor.sidebar` is **not** part of `iikiti:ready`. It exists only in editor
mode, so waiting for it on every page would block readiness on public pages.

## Members

### `config` (stable)

A read-only snapshot parsed from the `<script id="iikiti-config">` JSON block
emitted by the server. The keys present depend on the page context:

- Always: `timezone` (IANA name or `null`), `notifications`.
- Editor-capable users only (`canEdit: true`): `canEdit`, `canPublish`,
  `apiBase`, `apiToken`, `contextType`, `contextId`, `version`, `room`,
  `breakpoints`, `plugins`.

`notifications` is `{ autoDismiss, durationSeconds, position }`.

> `apiToken` is a credential for the signed-in user. Do not log it, persist it,
> or send it to third-party origins.

### `whenReady(name)` (stable)

Returns `Promise<void>` for a readiness name from the table above. See
[Load timing and readiness](#load-timing-and-readiness).

### `domReady` and `onLoad` (stable)

Promises that resolve when the DOM is parsed (`domReady`) or the window `load`
event fires (`onLoad`). Prefer `whenReady` for framework components; use these
only for general DOM work.

### `loader` (stable)

Dependency- and version-aware script/style loading with cached promises.

- `loader.loadScript(nameOrUrl, { strategy? })`: strategy is one of `async`,
  `defer`, `complete` (default) or `interaction`.
- `loader.loadStyle(url)`: loads a stylesheet once.
- `loader.registerLibrary(name, { url, version?, type?, deps? })`: registers a
  named library so later `loadScript(name)` calls resolve to it.
- `loader.loadIf(probe, { url, read, strategy? })`: resolves to the native value
  when `probe()` returns one (not `undefined`); otherwise loads the polyfill once
  and resolves to `read()`. Concurrent callers share one load.

### `plugins` (stable)

- `plugins.register({ name, entry?, css?, strategy?, deps? })`: queues a
  front-end plugin bundle. Bundles start on DOM ready in registration order.
- `plugins.list()`: returns the registered definitions.

### `notifications` (stable)

- `notifications.notify({ message, type?, duration?, action? })`: shows a toast.
  `type` is `info`, `success`, `warning` or `error`. `action` is
  `{ label, run }`. Auto-dismiss follows `config.notifications`.
- `notifications.dismiss(id)`: dismisses one notification.

### `components` (stable)

Imperative registry for layout components (`banner`, `header`, `footer`,
`sidebar`, `panel`).

- `components.create(name, el, opts?)`: instantiates a component on an element
  (replacing any existing instance). Returns the instance or `null`.
- `components.autoWire(scope?)`: wires any unwired `data-component` elements in
  `scope` (default `document`).
- `components.on(name, fn)` / `off(name, fn?)` / `emit(name, payload)`: pub/sub on
  the shared event bus. Returns an unsubscribe function from `on`.

```js
window.iikiti.components.create('sidebar', el, {
  triggers: 'scroll,edge,button',
  toggleSelector: '#sidebar-toggle',
});
window.iikiti.components.on('banner:update', (payload) => { /* ... */ });
```

### `bars` (stable)

The viewport bars stacking standard. Full details, including the
`data-iikiti-bar` markup, are in [front-end-ui-standard.md](front-end-ui-standard.md).

- `bars.register(el | null, opts)`: registers a bar and returns `{ id, el, destroy() }`
  or `null`. `opts` includes `id`, `side` (`top`/`right`/`bottom`/`left`), `order`,
  `sticky`, `resizable`, `mode`. Passing `null` creates a slot element.
- `bars.unregister(id)`: removes a bar and returns its element (or `null`).
- `bars.getOffset(side)`: pixels reserved by stack bars on that side.
- `bars.onChange(fn)`: fires after each re-layout.

### `theme` (stable)

- `theme.get()`: returns `true` when the dark theme is active, `false` otherwise.
- `theme.toggle()`: switches between light and dark.
- `theme.apply()`: applies the stored preference (or the OS preference when none is stored). Runs at page start.
- `theme.STORAGE_THEME_KEY`: the `localStorage` key holding the preference (`'theme'`).

### `tour` (experimental)

Tour and spotlight framework. Tours are sequences of steps that target elements
by `data-tour="<id>"` or a CSS selector.

```js
window.iikiti.tour.register({
  id: 'intro',
  name: 'Intro',
  steps: [
    { target: 'toolbar.layers', placement: 'bottom', title: 'Layers',
      content: 'Open the structure of this region.' },
  ],
});
window.iikiti.tour.start('intro');
```

### `editor` (experimental)

- `editor.launch()`: opens the front-end editor on the current page. Only
  meaningful for users with `canEdit`.
- `editor.sidebar` (experimental, available after `whenReady('editor.sidebar')`):
  registers sidebar sections, field controls, decorators and patches. Plugin
  editor UI bundles should wait for readiness, then use:

```js
const sidebar = await window.iikiti.whenReady('editor.sidebar')
  .then(() => window.iikiti.editor.sidebar);
```

`editor.sidebar` is set only in editor mode. On public pages it never exists
and `whenReady` never resolves for it.

## Related

- [plugin-development.md](plugin-development.md): building plugins that use these members.
- [front-end-ui-standard.md](front-end-ui-standard.md): viewport bars and layering.
- [front-end-editor.md](front-end-editor.md): editor architecture and extension points.
