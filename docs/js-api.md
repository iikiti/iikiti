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

```js
const { timezone, notifications } = window.iikiti.config;
console.log(timezone);                    // e.g. "Europe/Amsterdam" or null
console.log(notifications.position);      // e.g. "bottom-right"
```

`apiToken` (editor-capable users only) is a credential for the signed-in user.
Do not log it, persist it, or send it to third-party origins:

```js
const { apiBase, apiToken } = window.iikiti.config;
const res = await fetch(`${apiBase}/user`, {
  headers: { Authorization: `Bearer ${apiToken}` },
});
```

### `whenReady(name)` (stable)

Returns `Promise<void>` for a readiness name from the table above. See
[Load timing and readiness](#load-timing-and-readiness).

```js
window.iikiti.whenReady('bars').then(() => {
  console.log('bars offset:', window.iikiti.bars.getOffset('top'));
});
```

### `domReady` and `onLoad` (stable)

Promises that resolve when the DOM is parsed (`domReady`) or the window `load`
event fires (`onLoad`). Prefer `whenReady` for framework components; use these
only for general DOM work.

```js
await window.iikiti.domReady;
document.querySelectorAll('.gallery img').forEach(lazyInit);

await window.iikiti.onLoad;   // images, fonts and iframes have finished loading
```

### `loader` (stable)

Dependency- and version-aware script/style loading with cached promises.

- `loader.loadScript(nameOrUrl, { strategy? })`: strategy is one of `async`,
  `defer`, `complete` (default) or `interaction`.
- `loader.loadStyle(url, { strategy? })`: loads a stylesheet once. `strategy` is one of:
  - `complete` (default): inserted immediately; render-blocking, as before.
  - `async`: low priority and does not block layout. The link is added with
    `media="print"` and switched to `media="all"` once loaded.
  - `onload`: inserted after the DOM is ready.
  - `interaction`: inserted after the first pointer or key event.
- `loader.registerLibrary(name, { url, version?, type?, deps? })`: registers a
  named library so later `loadScript(name)` calls resolve to it. `deps` are loaded
  first. `version` is informational only; it does not select or cache-bust a file.
- `loader.loadIf(probe, { url, read, strategy? })`: resolves to the native value
  when `probe()` returns one (not `undefined`); otherwise loads the polyfill once
  and resolves to `read()`. Concurrent callers share one load.

```js
// Load a script after the page is interactive, unless the user never interacts.
await window.iikiti.loader.loadScript('/js/charts.js', { strategy: 'interaction' });

// Load a stylesheet once; repeated calls are no-ops.
await window.iikiti.loader.loadStyle('/css/charts.css');

// Low-priority stylesheet that does not block first paint.
window.iikiti.loader.loadStyle('/css/charts-extras.css', { strategy: 'async' });

// Stylesheet for below-the-fold content, inserted after DOM ready.
window.iikiti.loader.loadStyle('/css/gallery.css', { strategy: 'onload' });

// Stylesheet only needed once the user interacts with the page.
window.iikiti.loader.loadStyle('/css/editor-extras.css', { strategy: 'interaction' });

// Register a named library, then load it by name (and its dependencies).
window.iikiti.loader.registerLibrary('charts', {
  url: '/js/charts.js',
  version: '2.1.0',
  deps: ['/js/d3.js'],
});
await window.iikiti.loader.loadScript('charts');

// Use native Temporal when present; otherwise load the polyfill once.
const Temporal = await window.iikiti.loader.loadIf(
  () => globalThis.Temporal,
  { url: '/js/temporal-polyfill.js', read: () => globalThis.Temporal },
);
```

### `plugins` (stable)

- `plugins.register({ name, entry?, css?, strategy?, cssStrategy?, deps? })`: queues a
  front-end plugin bundle. Bundles start on DOM ready in registration order.
  `strategy` applies to `entry`; `cssStrategy` (default `complete`) applies to `css`
  and accepts the same values as `loader.loadStyle`.
- `plugins.list()`: returns the registered definitions.

```js
window.iikiti.plugins.register({
  name: 'acme-gallery',
  entry: '/plugins/acme-gallery/gallery.js',
  css: '/plugins/acme-gallery/gallery.css',
  strategy: 'defer',
});

console.log(window.iikiti.plugins.list().map((p) => p.name));
```

### `notifications` (stable)

- `notifications.notify({ message, type?, duration?, action? })`: shows a toast
  and returns its `id`. `type` is `info`, `success`, `warning` or `error`.
  `action` is `{ label, run }`. Auto-dismiss follows `config.notifications`.
- `notifications.dismiss(id)`: dismisses one notification.
- `notifications.on(fn)`: subscribes to `notify` and `dismiss` events; returns an
  unsubscribe function.

```js
const id = window.iikiti.notifications.notify({
  message: 'Settings saved',
  type: 'success',
  duration: 5,
});

window.iikiti.notifications.notify({
  message: 'Draft not published',
  type: 'warning',
  action: { label: 'Undo', run: () => undoPublish() },
});

window.iikiti.notifications.dismiss(id);

const stop = window.iikiti.notifications.on((event) => {
  if (event.kind === 'notify') console.log('shown:', event.notification.message);
});
stop(); // unsubscribe
```

### `components` (stable)

Imperative registry for layout components (`banner`, `header`, `footer`,
`sidebar`, `panel`).

- `components.create(name, el, opts?)`: instantiates a component on an element
  (replacing any existing instance). Returns the instance or `null`.
- `components.autoWire(scope?)`: wires any unwired `data-component` elements in
  `scope` (default `document`).
- `components.on(name, fn)` / `off(name, fn?)` / `emit(name, payload)`: pub/sub on
  the shared event bus. `on` returns an unsubscribe function.
- `components.classes`: the map of component names to their classes.
- `components.off(name, fn?)`: removes one listener, or all listeners for `name` when `fn` is omitted.
- `components.emit(name, payload)`: dispatches `payload` to listeners of `name`.

```js
window.iikiti.components.emit('banner:update', { id: 'promo' });
window.iikiti.components.off('banner:update'); // remove every listener for this event
```

```js
const sidebar = window.iikiti.components.create('sidebar', el, {
  triggers: 'scroll,edge,button',
  toggleSelector: '#sidebar-toggle',
});

window.iikiti.components.autoWire(document.getElementById('new-section'));

const unsubscribe = window.iikiti.components.on('banner:update', (payload) => {
  console.log('banner updated', payload);
});
window.iikiti.components.off('banner:update', fnRef); // remove one listener
window.iikiti.components.emit('banner:update', { id: 'promo' });
unsubscribe();

console.log(Object.keys(window.iikiti.components.classes)); // ['banner', 'header', ...]
```

### `bars` (stable)

The viewport bars stacking standard. Full details, including the
`data-iikiti-bar` markup, are in [front-end-ui-standard.md](front-end-ui-standard.md).

- `bars.register(el | null, opts)`: registers a bar and returns `{ id, el, destroy() }`
  or `null`. `opts` includes `id`, `side` (`top`/`right`/`bottom`/`left`), `order`,
  `sticky`, `resizable`, `mode`. Passing `null` creates a slot element.
- `bars.unregister(id)`: removes a bar and returns its element (or `null`).
- `bars.getOffset(side)`: pixels reserved by stack bars on that side.
- `bars.onChange(fn)`: fires after each re-layout; returns an unsubscribe function.
- `bars.autoWire(scope?)`: registers every `[data-iikiti-bar]` element in `scope`.

```js
// Wire bars added to the DOM after page load (e.g. by a plugin's injected markup).
window.iikiti.bars.autoWire(document.getElementById('plugin-root'));
```

```js
const bar = window.iikiti.bars.register(myHeaderEl, {
  id: 'site-header',
  side: 'top',
  order: 0,
  sticky: true,
});
bar.destroy();

window.iikiti.bars.register(null, { id: 'promo-slot', side: 'top', sticky: true });
window.iikiti.bars.unregister('promo-slot');

const topOffset = window.iikiti.bars.getOffset('top'); // px reserved at the top edge

const stop = window.iikiti.bars.onChange(({ sides }) => {
  console.log('top reserved:', sides.top);
});
stop();
```

### `theme` (stable)

- `theme.get()`: returns `true` when the dark theme is active, `false` otherwise.
- `theme.toggle()`: switches between light and dark.
- `theme.apply()`: applies the stored preference (or the OS preference when none is stored). Runs at page start.
- `theme.STORAGE_THEME_KEY`: the `localStorage` key holding the preference (`'theme'`).

```js
if (window.iikiti.theme.get()) {
  console.log('dark theme active');
}

document.getElementById('theme-button').addEventListener('click', () => {
  window.iikiti.theme.toggle();
});

// Re-apply the stored preference, e.g. after another tab changed it.
window.addEventListener('storage', (e) => {
  if (e.key === window.iikiti.theme.STORAGE_THEME_KEY) window.iikiti.theme.apply();
});

console.log(window.iikiti.theme.STORAGE_THEME_KEY); // "theme"
```

### `tour` (experimental)

Tour and spotlight framework. Tours are sequences of steps that target elements
by `data-tour="<id>"` or a CSS selector.

- `tour.register(tour)`: registers a tour `{ id, name, steps }`.
- `tour.list()`: returns the registered tours.
- `tour.start(id, stepIndex?)`: starts a tour at a step (default `0`).
- `tour.next()` / `tour.prev()`: moves to the next or previous step. `next` on the
  last step ends the tour.
- `tour.jumpTo(index)`: moves to a step by index (ignored when out of range).
- `tour.end()`: ends the active tour.
- `tour.getState()`: returns `{ tourId, stepIndex, total, step }` for the active
  tour, or `null` when no tour is running.
- `tour.on(fn)`: subscribes to state changes. `fn` is called immediately with the
  current state; returns an unsubscribe function.
- `tour.registerAction(type, handler)`: registers a handler for a step action
  type used in a step's `before`/`after` lists. `handler(action, step)` receives
  the action object and its step.
- `tour.highlight(target, on)`: toggles the spotlight class on a target.
- `tour.resolveTarget(target)`: resolves a `data-tour` id, selector or element.

```js
window.iikiti.tour.highlight('toolbar.layers', true);   // spotlight on
window.iikiti.tour.highlight('toolbar.layers', false);  // spotlight off

const el = window.iikiti.tour.resolveTarget('toolbar.layers'); // HTMLElement | null
const byId = window.iikiti.tour.resolveTarget('#publish');
```


```js
window.iikiti.tour.register({
  id: 'intro',
  name: 'Intro',
  steps: [
    { target: 'toolbar.layers', placement: 'bottom', title: 'Layers',
      content: 'Open the structure of this region.' },
    { target: '#publish', placement: 'left', title: 'Publish',
      content: 'Publish when you are done.' },
  ],
});

window.iikiti.tour.start('intro');
window.iikiti.tour.next();

const stop = window.iikiti.tour.on((state) => console.log('tour state', state));
stop();

window.iikiti.tour.registerAction('flash', (action) => {
  window.iikiti.tour.highlight(action.target, true);
});
// A step can then run it: { target: '#publish', before: [{ type: 'flash', target: '#publish' }] }

console.log(window.iikiti.tour.getState()); // null when no tour is running
window.iikiti.tour.end();
```

### `editor` (experimental)

- `editor.launch()`: opens the front-end editor on the current page. Only
  meaningful for users with `canEdit`.
- `editor.sidebar` (experimental, available after `whenReady('editor.sidebar')`):
  registers sidebar sections, field controls, decorators and patches. Plugin
  editor UI bundles should wait for readiness, then use:

```js
// Open the editor from a custom button (editor-capable users only).
document.getElementById('edit-button').addEventListener('click', () => {
  window.iikiti.editor.launch();
});

// Plugin editor bundles: wait for the sidebar, then use its API.
const sidebar = await window.iikiti.whenReady('editor.sidebar')
  .then(() => window.iikiti.editor.sidebar);
sidebar.registerSection({ id: 'acme', label: 'Acme', build: (ctx) => [] });
```

`editor.sidebar` is set only in editor mode. On public pages it never exists
and `whenReady` never resolves for it.

## Related

- [plugin-development.md](plugin-development.md): building plugins that use these members.
- [front-end-ui-standard.md](front-end-ui-standard.md): viewport bars and layering.
- [front-end-editor.md](front-end-editor.md): editor architecture and extension points.
