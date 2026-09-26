/// <reference types="node" />
/**
 * Shared layout component library.
 *
 * These are vanilla-JS enhancement controllers (NOT web components) that attach
 * behaviour + minimal themeable styling to ordinary DOM elements. They run on
 * both the public front-end and the admin SPA because `assets/js/iikiti/` is
 * loaded on every page via `base/layout.twig`.
 *
 * Declarative usage:
 *   <header data-component="header" data-sticky="scroll" data-sticky-toggle="#menu">
 *
 * Imperative usage:
 *   iikiti.components.create('header', el, { sticky: ['scroll'], ... })
 *
 * Theme: components style themselves via neutral CSS custom properties
 * (--ik-panel-bg / --ik-panel-border / --ik-panel-text / --ik-accent). Either
  * theme may override these (public app.css defaults; admin.css palette).
  */

import { StickyController } from './sticky.js';

export const STORAGE_THEME_KEY = 'theme';

function isDarkPreferred() {
  if (typeof window === 'undefined' || !window.matchMedia) return false;
  return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function getStoredTheme() {
  if (typeof localStorage === 'undefined') return null;
  return localStorage.getItem(STORAGE_THEME_KEY);
}

export function applyStoredTheme() {
  const el = document.documentElement;
  const stored = getStoredTheme();
  const dark = stored === 'dark' || (stored === null && isDarkPreferred());
  if (dark) el.classList.add('dark'); else el.classList.remove('dark');
}

export function resolveTheme() {
  if (typeof document === 'undefined') return false;
  return document.documentElement.classList.contains('dark');
}

export function toggleTheme() {
  resolveTheme() ? applyTheme('light') : applyTheme('dark');
}

export function applyTheme(theme) {
  const el = document.documentElement;
  if (theme === 'dark') {
    el.classList.add('dark');
    localStorage.setItem(STORAGE_THEME_KEY, 'dark');
  } else {
    el.classList.remove('dark');
    localStorage.setItem(STORAGE_THEME_KEY, 'light');
  }
}

function parseTriggers(value) {
  if (!value) return [];
  if (Array.isArray(value)) return value.filter(Boolean);
  if (typeof value === 'string') return value.split(',').map((s) => s.trim()).filter(Boolean);
  return [];
}

function getNumber(value, fallback) {
  const n = Number(value);
  return Number.isFinite(n) ? n : fallback;
}

const defaultOptions = {
  position: 'static',
  sticky: [],
  triggers: [],
  threshold: 80,
  proximity: 24,
  revealDistance: 80,
  enterDelay: 120,
  exitDelay: 300,
  toggleSelector: null,
  targetSelector: null,
  onShow: null,
  onHide: null,
};

/**
 * Base class for layout components (banner/header/footer/sidebar/panel).
 * Manages: element lifecycle, content swapping, refresh hooks, and the
 * StickyController behaviour layer.
 */
export class LayoutComponent {
  /**
   * @param {HTMLElement} el
   * @param {object} [opts]
   */
  constructor(el, opts = {}) {
    /** @type {HTMLElement} */
    this.el = el;
    this.opts = { ...defaultOptions, ...opts };
    this.opts.triggers = parseTriggers(this.opts.triggers);
    this.opts.sticky = parseTriggers(this.opts.sticky ?? this.opts.triggers);
    if (el) el.dataset.componentReady = 'true';
    this.destroyed = false;
    this._visible = true;
    this._setupAttributes();
    this._initSticky();
  }

  _setupAttributes() {
    const { el, opts } = this;
    if (!el) return;
    if (!opts.position || opts.position === 'static') {
      el.style.position = opts.position === 'absolute' ? 'absolute' : '';
    } else if (opts.position === 'sticky') {
      el.style.position = 'sticky';
      el.style.top = opts.stickyTop ?? '0';
    } else if (opts.position === 'absolute') {
      el.style.position = 'absolute';
    }
    el.setAttribute('data-iikiti-component', this.componentName);
  }

  _initSticky() {
    if (!this.el) return;
    if (!this.opts.sticky.length) return;
    this.sticky = new StickyController(this.el, {
      triggers: this.opts.sticky,
      threshold: this.opts.threshold,
      proximity: this.opts.proximity,
      revealDistance: this.opts.revealDistance,
      enterDelay: this.opts.enterDelay,
      exitDelay: this.opts.exitDelay,
      toggleSelector: this.opts.toggleSelector,
      targetSelector: this.opts.targetSelector,
      initiallyVisible: this.opts.initiallyVisible ?? (typeof window === 'undefined' || !window.matchMedia('(max-width: 767px)').matches),
      onShow: (reason) => {
        this._visible = true;
        this.onShow?.(reason);
        this.opts.onShow?.(reason);
      },
      onHide: (reason) => {
        this._visible = false;
        this.onHide?.(reason);
        this.opts.onHide?.(reason);
      },
    });
  }

  /** Override in subclass. @param {string} reason */
  onShow(reason) {}
  /** Override in subclass. @param {string} reason */
  onHide(reason) {}

  /** @param {string} html */
  setContent(html) {
    if (!this.contentEl) {
      this.contentEl = this.el.querySelector('[data-iikiti-content]');
    }
    if (this.contentEl) {
      this.contentEl.innerHTML = html;
    } else if (this.el) {
      this.el.innerHTML = html;
    }
    return this;
  }

  setContentNode(node) {
    const target = this.contentEl || this.el;
    if (!target) return this;
    if (this.contentEl) {
      this.contentEl.replaceChildren(node);
    } else {
      target.replaceChildren(node);
    }
    return this;
  }

  async refresh() {
    const { el } = this;
    if (!el) return null;
    const source = el.dataset.iikitiSource;
    if (!source) return null;
    try {
      const res = await fetch(source, { credentials: 'same-origin' });
      if (!res.ok) throw new Error(`refresh ${res.status}`);
      const html = await res.text();
      this.setContent(html);
      this.onRefresh?.(html);
      return html;
    } catch (e) {
      this.onError?.(e);
      return null;
    }
  }

  destroy() {
    if (this.destroyed) return;
    this.sticky?.destroy();
    this.onDestroy?.();
    this.destroyed = true;
  }

  get componentName() {
    return this.constructor.name;
  }
}

