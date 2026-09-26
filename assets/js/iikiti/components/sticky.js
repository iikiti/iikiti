/// <reference types="node" />
/**
 * StickyController — composes scroll, edge-proximity, hover-target and
 * button-toggle reveal/hide behaviours for a layout panel.
 *
 * Triggers are independent and can be combined. The panel is considered
 * "shown" when ANY trigger requests it; it hides only when all active
 * triggers agree it should be hidden (debounced).
 *
 * Declarative (on the panel element):
 *   data-sticky="scroll,edge,button"
 *   data-sticky-toggle="#toggle-id"   (button)
 *   data-sticky-target=".edge-tab"    (hover-target)
 *   data-sticky-threshold="80"
 *   data-sticky-proximity="24"
 *
 * Imperative:
 *   new StickyController(el, { triggers: ['scroll','edge','button'], ... })
 */
const parseTriggers = (value) => {
  if (!value) return [];
  return (Array.isArray(value) ? value : String(value).split(',')).map((s) => s.trim()).filter(Boolean);
};
const num = (v, f) => (Number.isFinite(Number(v)) ? Number(v) : f);

export class StickyController {
  /** @param {HTMLElement} el @param {object} opts */
  constructor(el, opts = {}) {
    /** @type {HTMLElement} */
    this.el = el;
    this.opts = {
      triggers: parseTriggers(opts.triggers),
      threshold: num(opts.threshold, 80),
      proximity: num(opts.proximity, 24),
      revealDistance: num(opts.revealDistance, 80),
      enterDelay: num(opts.enterDelay, 120),
      exitDelay: num(opts.exitDelay, 300),
      toggleSelector: opts.toggleSelector,
      targetSelector: opts.targetSelector,
      onShow: opts.onShow,
      onHide: opts.onHide,
    };

    /** @type {Record<string,boolean>} per-trigger demand to show */
    this.demands = { scroll: false, edge: false, 'hover-target': false, button: false };
    // Mobile drawers start closed; everything else starts shown.
    this.visible = opts.initiallyVisible !== false;
    this._raf = null;
    this._hideTimer = null;
    this._showTimer = null;
    this._lastScroll = 0;
    this._lastDirection = 'down';
    this._init();
  }

  /**
   * Force the shown/hidden state (used by hosts when the viewport mode
   * changes, e.g. sidebar drawer closed on mobile).
   * @param {boolean} show
   */
  setVisible(show) {
    this.demands.button = false;
    this._apply(show, 'init');
  }

  _init() {
    const { el } = this;
    if (!el) return;
    el.setAttribute('data-iikiti-sticky', this.opts.triggers.join(','));
    const t = this.opts.toggleSelector || el.dataset.stickyToggle;
    const tg = this.opts.targetSelector || el.dataset.stickyTarget;

    this._bound = {
      onScroll: this._onScroll.bind(this),
      onPointerMove: this._onPointerMove.bind(this),
      onPointerLeave: this._onPointerLeave.bind(this),
      onResize: this._onResize.bind(this),
      onToggleKeydown: this._onToggleKeydown.bind(this),
    };

    if (this.opts.triggers.includes('scroll')) {
      window.addEventListener('scroll', this._bound.onScroll, { passive: true });
      this._lastScroll = window.scrollY;
    }
    if (this.opts.triggers.includes('edge') || this.opts.triggers.includes('hover-target')) {
      document.addEventListener('pointermove', this._bound.onPointerMove, { passive: true });
      document.addEventListener('pointerleave', this._bound.onPointerLeave, { passive: true });
    }
    if (this.opts.triggers.includes('resize')) {
      window.addEventListener('resize', this._bound.onResize);
    }
    if (t) {
      const btn = typeof t === 'string' ? document.querySelector(t) : t;
      if (btn) {
        btn.dataset.stickyToggleFor = el.id || '';
        btn.setAttribute('aria-expanded', String(this.visible));
        btn.addEventListener('click', this._onToggleClick.bind(this));
        btn.addEventListener('keydown', this._bound.onToggleKeydown);
      }
    }
    if (tg) {
      const tgt = typeof tg === 'string' ? document.querySelector(tg) : tg;
      if (tgt) {
        tgt.dataset.stickyTargetFor = el.id || '';
        tgt.addEventListener('mouseenter', () => this._demand('hover-target', true));
        tgt.addEventListener('mouseleave', () => this._demand('hover-target', false));
      }
    }
  }

  _demand(trigger, value) {
    this.demands[trigger] = value;
    this._reconcile();
  }

  _onScroll() {
    const now = window.scrollY;
    const delta = now - this._lastScroll;
    this._lastDirection = delta > 0 ? 'down' : 'up';
    this._lastScroll = now;
    if (delta > 10) this._demand('scroll', false);
    else if (delta < -10) this._demand('scroll', true);
    else if (now <= this.opts.revealDistance) this._demand('scroll', true);
  }

  _onPointerMove(e) {
    const { el, opts } = this;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const vp = {
      left: 0,
      right: window.innerWidth,
      top: 0,
      bottom: window.innerHeight,
    };
    let near = false;
    if (rect.left <= opts.proximity) {
      near = e.clientX <= opts.proximity;
    } else if (rect.right >= window.innerWidth - opts.proximity) {
      near = e.clientX >= window.innerWidth - opts.proximity;
    } else if (rect.top <= opts.proximity) {
      near = e.clientY <= opts.proximity;
    } else if (rect.bottom >= window.innerHeight - opts.proximity) {
      near = e.clientY >= window.innerHeight - opts.proximity;
    }
    this._demand('edge', near);
    if (near) this._demand('hover-target', true);
  }

  _onPointerLeave() {
    this._demand('edge', false);
    this._demand('hover-target', false);
  }

  _onResize() {
    if (this.visible) this._reconcile();
  }

  _onToggleClick(e) {
    const btn = e.currentTarget;
    const expanded = btn.getAttribute('aria-expanded') === 'true';
    this._set(!expanded, 'button');
  }

  _onToggleKeydown(e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      this._onToggleClick(e);
    }
  }

  _set(show, trigger) {
    if (trigger) this.demands[trigger] = show;
    this._reconcile();
  }

  _reconcile() {
    const anyDemand = Object.values(this.demands).some(Boolean);
    // Button toggle is an explicit override: if button is in "shown" state, show.
    if (this.opts.triggers.includes('button') && this.demands.button) {
      this._apply(true, 'button');
      return;
    }
    if (this.opts.triggers.length && !anyDemand) {
      this._apply(false, this._lastReason());
    } else {
      this._apply(true, 'demand');
    }
  }

  _lastReason() {
    if (this.opts.triggers.includes('scroll') && !this.demands.scroll) return 'scroll';
    if (this.opts.triggers.includes('edge') && !this.demands.edge) return 'edge';
    return 'scroll';
  }

  _apply(show, reason) {
    if (show === this.visible) return;
    this.visible = show;
    const { el } = this;
    if (!el) return;
    clearTimeout(this._showTimer);
    clearTimeout(this._hideTimer);
    const applyNow = () => {
      if (show) {
        el.classList.remove('iikiti-hidden', 'iikiti-translate-out');
        el.classList.add('iikiti-translate-in');
        el.setAttribute('aria-hidden', 'false');
        this.opts.onShow?.(reason);
      } else {
        el.classList.add('iikiti-hidden');
        el.setAttribute('aria-hidden', 'true');
        this.opts.onHide?.(reason);
      }
      const toggleSel = this.opts.toggleSelector || el.dataset.stickyToggle;
      const toggleBtn = toggleSel ? document.querySelector(toggleSel) : null;
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded', String(show));
    };
    if (show) {
      this._showTimer = setTimeout(applyNow, this.opts.enterDelay);
    } else {
      this._hideTimer = setTimeout(applyNow, this.opts.exitDelay);
    }
  }

  destroy() {
    window.removeEventListener('scroll', this._bound.onScroll);
    document.removeEventListener('pointermove', this._bound.onPointerMove);
    document.removeEventListener('pointerleave', this._bound.onPointerLeave);
    window.removeEventListener('resize', this._bound.onResize);
    const { el } = this;
    if (el) {
      el.classList.remove('iikiti-hidden', 'iikiti-translate-out', 'iikiti-translate-in');
      el.setAttribute('aria-hidden', 'false');
    }
    clearTimeout(this._showTimer);
    clearTimeout(this._hideTimer);
  }
}
