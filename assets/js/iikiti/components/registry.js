/// <reference types="node" />
/**
 * Component registry.
 *
 * Auto-wires `data-component="..."` elements on `domReady` (for the public
 * front-end) and exposes an imperative API consumed by the admin SPA.
 *
 *   window.iikiti.components.create('sidebar', el, opts)
 *   window.iikiti.components.on('banner:update', fn)
 */
import { domReady } from '../domready.js';
import { Banner } from './banner.js';
import { Header } from './header.js';
import { Footer } from './footer.js';
import { Sidebar } from './sidebar.js';
import { Panel } from './panel.js';

const classes = { banner: Banner, header: Header, footer: Footer, sidebar: Sidebar, panel: Panel };

const instances = new WeakMap();
const events = {
  _listeners: new Map(),
  on(name, fn) {
    const set = this._listeners.get(name) ?? new Set();
    set.add(fn);
    this._listeners.set(name, set);
    return () => set.delete(fn);
  },
  off(name, fn) {
    const set = this._listeners.get(name);
    if (!set) return;
    if (fn) set.delete(fn); else this._listeners.delete(name);
  },
  emit(name, payload) {
    const set = this._listeners.get(name);
    if (!set) return;
    for (const fn of [...set]) fn(payload);
  },
};

function readOpts(el) {
  const opts = {};
  const sticky = el.dataset.sticky;
  if (sticky) opts.triggers = sticky;
  if (el.dataset.position) opts.position = el.dataset.position;
  if (el.dataset.stickyThreshold) opts.threshold = Number(el.dataset.stickyThreshold);
  if (el.dataset.stickyProximity) opts.proximity = Number(el.dataset.stickyProximity);
  if (el.dataset.stickyToggle) opts.toggleSelector = el.dataset.stickyToggle;
  if (el.dataset.stickyTarget) opts.targetSelector = el.dataset.stickyTarget;
  if (el.dataset.dismissible) opts.dismissible = el.dataset.dismissible === 'true';
  return opts;
}

function create(name, el, opts = {}) {
  if (!el) return null;
  if (instances.has(el)) instances.get(el).destroy();
  const Cls = classes[name];
  if (!Cls) {
    console.warn(`iikiti: unknown component "${name}"`);
    return null;
  }
  const instance = new Cls(el, { ...readOpts(el), ...opts });
  instances.set(el, instance);
  return instance;
}

function autoWire(scope = document) {
  scope.querySelectorAll('[data-component]').forEach((el) => {
    if (el.dataset.iikitiNoAuto === 'true') return;
    if (el.dataset.componentReady === 'true') return;
    const name = el.dataset.component;
    create(name, el);
  });
}

let started = false;
function start() {
  if (started) return;
  started = true;
  domReady.then(() => {
    autoWire();
    document.addEventListener('iikiti:init', (e) => {
      const root = (e?.detail && e.detail.root) || document;
      if (root && root.querySelectorAll) autoWire(root);
    });
  });
}
start();

export const components = {
  create,
  autoWire,
  on: events.on.bind(events),
  off: events.off.bind(events),
  emit: events.emit.bind(events),
  classes,
};

export { autoWire, create };