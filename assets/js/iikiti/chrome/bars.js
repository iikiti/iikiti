/**
 * BarsManager — the iikiti viewport "bars" stacking standard.
 *
 * A "bar" is any chrome element attached to one side of the viewport
 * (top/bottom strip or left/right rail): the editor toolbar, theme headers,
 * pencil banners, footers, sidebars, …
 *
 * Stack guarantees:
 *  - "stack" bars (default) never cover each other or the content. Top and
 *    bottom bars are re-slotted as direct <body> children in stack order and
 *    push the page content via normal document flow; they span the full
 *    viewport width (the manager cancels the rail reserve with negative
 *    inline margins), so a top header always runs across the rails. A
 *    `sticky` bar pins to its side while the page scrolls (inline sticky
 *    offsets accumulate the sizes of the bars closer to the viewport edge).
 *    Left/right rails are fixed elements spanning between the top and bottom
 *    stacks (i.e. below the header, above the footer) and reserve horizontal
 *    space via the `--iikiti-bars-left/right` body padding.
 *  - `mode: "fixed" | "absolute"` is the explicit overlay exemption: the
 *    element is left exactly where the host put it and is not measured.
 *  - Resizable bars (opt-in) get a drag handle on the edge facing the
 *    content via the shared dragResize helper (size persisted to
 *    localStorage as `iikiti.bar.<id>.size`).
 *
 * DOM notes: stack bars are re-slotted to <body> level because `position:
 * sticky` only pins within the parent's box — a bar nested in theme wrappers
 * could never pin for the whole page otherwise. Ancestors change; classes
 * don't. Bars also MUST NOT live under an ancestor with `transform` or
 * `overflow: hidden` on `html`/`body` (breaks sticky/fixed pinning).
 * The Symfony WebProfiler toolbar is treated as fixed bottom chrome: its
 * wrapper is kept at the end of the flow (its clearer reserved at the page
 * bottom) and its height is counted into `--iikiti-bars-bottom` so nothing we
 * manage sits under it.
 *
 * Consuming:
 *   Declarative (themes / plugin server UI):
 *     <div data-iikiti-bar="top" data-iikiti-bar-order="0"
 *          data-iikiti-bar-sticky="true" data-iikiti-bar-resizable="false"
 *          data-iikiti-bar-mode="stack|fixed|absolute"
 *          data-iikiti-bar-size="240"        <!-- rails: explicit width -->
 *          data-iikiti-bar-min-size="36" data-iikiti-bar-max-size="240"
 *          data-iikiti-bar-id="my-theme-banner">…</div>
 *   Imperative (plugin site_ui bundles / the editor):
 *     iikiti.bars.register(el, { id, side, order, sticky, resizable, mode, … })
 *     iikiti.bars.register(null, { side: 'top', sticky: true, … })  // creates a slot div for e.g. a Svelte mount
 *     iikiti.bars.unregister(id) / iikiti.bars.getOffset('top')
 *   Events: `iikiti.bars.onChange(fn)` fires after each re-layout (also
 *   emitted on the shared bus as `iikiti:bars:change`).
 *
 * Stacking order semantics: within a side, bars are ordered by `order`
 * ascending, then registration sequence — **lower order = closer to the
 * viewport edge**. The editor toolbar registers with order -100.
 */

import { events } from '../events.js';
import { createResizeHandle, contentEdgeForSide, readPersistedSize } from './dragResize.js';

const SIDES = ['top', 'right', 'bottom', 'left'];
const SIDES_SET = new Set(SIDES);
const SIDE_VAR = {
	top: '--iikiti-bars-top',
	right: '--iikiti-bars-right',
	bottom: '--iikiti-bars-bottom',
	left: '--iikiti-bars-left',
};
const DEFAULT_RAIL_WIDTH = 220;
const DEFAULT_MIN_SIZE = 36;

// Symfony WebProfilerBundle toolbar (development only). The wrapper holds a
// static `.sf-toolbar-clearer` spacer plus the fixed bar pinned to the
// viewport bottom; see `_syncWebProfiler`/`_externalBottom`.
const SYMFONY_TOOLBAR_SELECTOR = '.sf-toolbar';
const SYMFONY_CLEARER_SELECTOR = '.sf-toolbar-clearer';

const num = (v, fallback) => (Number.isFinite(Number(v)) ? Number(v) : fallback);

/**
 * @typedef {object} BarRecord
 * @property {string} id
 * @property {HTMLElement} el
 * @property {'top'|'right'|'bottom'|'left'} side
 * @property {'stack'|'fixed'|'absolute'} mode
 * @property {number} order
 * @property {boolean} sticky
 * @property {boolean} resizable
 * @property {number} minSize
 * @property {number} maxSize  NaN = viewport-relative default (dragResize)
 * @property {number} seq
 * @property {boolean} slotOwned
 * @property {() => void} resizeDestroy
 */

class BarsManager {
	constructor() {
		/** @type {Map<string, BarRecord>} id → record */
		this._bars = new Map();
		/** @type {WeakMap<HTMLElement,string>} el → id (dedupe + lookup) */
		this._byElement = new WeakMap();
		this._seq = 0;
		/** @type {ResizeObserver|null} */
		this._ro = null;
		this._rafId = 0;
		/** @type {Set<Function>} */
		this._changeListeners = new Set();
		this._started = false;
	}

	/**
	 * Register a bar. Pass an existing element, or `null` to have a slot
	 * element created (for frameworks that mount into it).
	 *
	 * @param {HTMLElement|null} el
	 * @param {object} [opts] when `el` is given, opts override data-* attributes
	 * @param {string} [opts.id]
	 * @param {'top'|'right'|'bottom'|'left'} [opts.side]
	 * @param {'stack'|'fixed'|'absolute'} [opts.mode]
	 * @param {number} [opts.order] lower = closer to the viewport edge
	 * @param {boolean} [opts.sticky] pin to the viewport while scrolling
	 * @param {boolean} [opts.resizable] attach a drag-to-resize handle
	 * @param {number} [opts.size] rails: explicit initial width (px)
	 * @param {number} [opts.minSize]
	 * @param {number} [opts.maxSize]
	 * @returns {{id:string, el:HTMLElement, destroy():void} | null}
	 */
	register(el, opts = {}) {
		const ds = el?.dataset ?? {};
		const side = SIDES_SET.has(opts.side) ? opts.side : ds.iikitiBar;
		if (!SIDES_SET.has(side)) {
			console.warn('iikiti.bars: missing or invalid side (top|right|bottom|left)', opts);
			return null;
		}
		if (el && this._byElement.has(el)) {
			const existing = this._bars.get(/** @type {string} */ (this._byElement.get(el)));
			if (existing) return this._public(existing);
		}

		let id = opts.id || ds.iikitiBarId || `iikiti-bar-${++this._seq}`;
		while (this._bars.has(id)) id = `${id}-${++this._seq}`;

		const mode = /** @type {'stack'|'fixed'|'absolute'} */ (
			['stack', 'fixed', 'absolute'].includes(opts.mode ?? ds.iikitiBarMode)
				? (opts.mode ?? ds.iikitiBarMode)
				: 'stack'
		);
		const bar = {
			id,
			el: el || this._createSlot(/** @type {'top'|'right'|'bottom'|'left'} */ (side), id),
			side: /** @type {'top'|'right'|'bottom'|'left'} */ (side),
			mode,
			order: num(opts.order ?? ds.iikitiBarOrder, 0),
			sticky: opts.sticky !== undefined ? opts.sticky === true : ds.iikitiBarSticky === 'true',
			resizable: opts.resizable !== undefined ? opts.resizable === true : ds.iikitiBarResizable === 'true',
			minSize: num(opts.minSize ?? ds.iikitiBarMinSize, DEFAULT_MIN_SIZE),
			maxSize: num(opts.maxSize ?? ds.iikitiBarMaxSize, NaN),
			seq: ++this._seq,
			slotOwned: !el,
			resizeDestroy: () => {},
		};

		this._bars.set(id, bar);
		this._byElement.set(bar.el, id);

		bar.el.dataset.iikitiBarManaged = 'true';
		bar.el.dataset.iikitiBarSide = bar.side;

		if (mode === 'fixed' || mode === 'absolute') {
			// Explicit overlay designation: never moved, never measured.
			this._schedule();
			return this._public(bar);
		}

		bar.el.classList.add('iikiti-bar');
		if (bar.el.id) bar.el.dataset.iikitiBarId = bar.id;
		this._observe(bar.el);

		if (bar.side === 'left' || bar.side === 'right') {
			this._initRailSize(bar, opts, ds);
		}

		if (bar.resizable) {
			const handle = createResizeHandle(bar.el, {
				edge: contentEdgeForSide(bar.side),
				min: bar.minSize,
				max: Number.isNaN(bar.maxSize) ? undefined : bar.maxSize,
				persistKey: `bar.${bar.id}.size`,
				className: 'iikiti-bar__resize-handle',
			});
			handle.applyPersisted();
			bar.resizeDestroy = handle.destroy;
		}

		this._schedule();
		return this._public(bar);
	}

	/**
	 * @param {string} id
	 * @param {object} [opts]
	 * @param {boolean} [opts.removeSlot] slot-owned elements are removed from
	 *   the DOM unless this is false; provided elements keep their position
	 * @returns {HTMLElement | null}
	 */
	unregister(id, opts = {}) {
		const bar = this._bars.get(id);
		if (!bar) return null;
		this._ro?.unobserve(bar.el);
		bar.resizeDestroy?.();
		const removed = bar.el;
		delete bar.el.dataset.iikitiBarManaged;
		if (bar.slotOwned && opts.removeSlot !== false) {
			bar.el.remove();
		} else if (bar.mode === 'stack') {
			this._clearManagedStyles(bar);
		}
		this._bars.delete(id);
		this._byElement.delete(bar.el);
		this._schedule();
		return removed;
	}

	/**
	 * Total reserved size (px) for a side — stack bars only.
	 * @param {'top'|'right'|'bottom'|'left'} side
	 * @returns {number}
	 */
	getOffset(side) {
		if (!SIDES_SET.has(side)) return 0;
		const stackBars = this._barsFor(side);
		return stackBars.reduce(
			(sum, bar) => sum + (side === 'top' || side === 'bottom'
				? Math.max(0, Math.round(bar.el.getBoundingClientRect().height))
				: Math.max(0, Math.round(bar.el.getBoundingClientRect().width))),
			0,
		);
	}

	/**
	 * Registered bars, optionally filtered by side.
	 * @param {'top'|'right'|'bottom'|'left'} [side]
	 */
	list(side) {
		return [...this._bars.values()]
			.filter((b) => !side || b.side === side)
			.map((b) => ({ id: b.id, side: b.side, order: b.order, sticky: b.sticky, resizable: b.resizable, mode: b.mode }));
	}

	/** @param {Function} fn called with `{ sides: {top,right,bottom,left} }` */
	onChange(fn) {
		this._changeListeners.add(fn);
		return () => this._changeListeners.delete(fn);
	}

	/**
	 * Scan a scope for declarative bars (`[data-iikiti-bar]`).
	 * @param {ParentNode} [scope]
	 */
	autoWire(scope = document) {
		scope.querySelectorAll('[data-iikiti-bar]').forEach((el) => {
			this.register(/** @type {HTMLElement} */ (el));
		});
	}

	/**
	 * Start DOM-event listeners (idempotent). Called by the framework entry
	 * on domReady; plugins injecting markup later dispatch `iikiti:init`
	 * (same contract as the components registry).
	 */
	start() {
		if (this._started) return;
		this._started = true;
		document.addEventListener('iikiti:init', (e) => {
			const root = /** @type {any} */ (e)?.detail?.root;
			if (root && root.querySelectorAll) this.autoWire(root);
		});
		window.addEventListener('resize', () => this._schedule());
		// Initial pass so CSS var totals (including external bottom chrome such
		// as the web profiler toolbar) are correct even with no bars registered,
		// plus a pass once everything (profiler toolbar) is on the page.
		this._schedule();
		window.addEventListener('load', () => this._schedule());
	}

	// ── internals ─────────────────────────────────────────────────────────

	/** @param {BarRecord} bar */
	_public(bar) {
		return {
			id: bar.id,
			el: bar.el,
			side: bar.side,
			mode: bar.mode,
			destroy: () => this.unregister(bar.id),
		};
	}

	/**
	 * @param {'top'|'right'|'bottom'|'left'} side
	 * @returns {BarRecord[]} registered stack bars of that side, stacking order
	 */
	_barsFor(side) {
		return [...this._bars.values()]
			.filter((b) => b.side === side && b.mode === 'stack')
			.sort((a, b) => a.order - b.order || a.seq - b.seq);
	}

	/**
	 * @param {'top'|'right'|'bottom'|'left'} side
	 * @param {string} id
	 */
	_createSlot(side, id) {
		const slot = document.createElement('div');
		slot.className = 'iikiti-bar iikiti-bar--slot';
		slot.dataset.iikitiBarSide = side;
		slot.dataset.iikitiBarId = id;
		return slot;
	}

	/**
	 * Rails need an explicit width (fixed positioning + auto width would be
	 * circular). Precedence: persisted (resizable rails) → opts/dataset
	 * `size` → authored inline width → standard default.
	 *
	 * @param {BarRecord} bar
	 * @param {object} opts
	 * @param {*} ds element dataset
	 */
	_initRailSize(bar, opts, ds) {
		const persisted = bar.resizable ? readPersistedSize(`bar.${bar.id}.size`) : null;
		const fromOpt = num(opts.size ?? ds.iikitiBarSize, NaN);
		let width = persisted?.w ?? (Number.isNaN(fromOpt) ? NaN : fromOpt);
		if (!Number.isFinite(width) || width <= 0) {
			const authored = parseFloat(bar.el.style.width || '');
			width = Number.isFinite(authored) && authored > 0 ? authored : DEFAULT_RAIL_WIDTH;
		}
		bar.el.style.width = `${Math.round(width)}px`;
	}

	/** @param {HTMLElement} el */
	_observe(el) {
		this._ro ??= new ResizeObserver(() => this._schedule());
		this._ro.observe(el);
	}

	_schedule() {
		if (this._rafId) return;
		this._rafId = requestAnimationFrame(() => {
			this._rafId = 0;
			this._apply();
		});
	}

	/** @param {Element} el */
	_isManaged(el) {
		return el instanceof HTMLElement && el.dataset.iikitiBarManaged === 'true';
	}

	/**
	 * Place `el` immediately before `ref` (append when `ref` is null), skipping
	 * the move when it is already there — a redundant insertBefore/appendChild
	 * still detaches and reinserts the node.
	 *
	 * @param {HTMLElement} el
	 * @param {Element|null} ref
	 */
	_placeBefore(el, ref) {
		const body = document.body;
		if (el.parentNode === body && el.nextElementSibling === (ref ?? null)) return;
		body.insertBefore(el, ref ?? null);
	}

	/** @param {BarRecord} bar @param {number} px */
	_height(bar, px) {
		return Math.max(0, Math.round(px));
	}

	/**
	 * The Symfony WebProfilerBundle toolbar (development only) is a static
	 * "clearer" spacer followed by a fixed bar pinned to the viewport bottom.
	 * The clearer must reserve space after the page content — when content
	 * mounts after it (e.g. the editor canvas) it ends up mid-flow and pushes
	 * content down at the top. Keep the wrapper at the end of the flow (just
	 * before our own bottom bars) and observe it so open/collapse re-layouts
	 * recompute. No-op when the toolbar is absent (production).
	 *
	 * @param {Element|null} ref first bottom bar (the wrapper goes before it)
	 */
	_syncWebProfiler(ref) {
		const wrapper = document.querySelector(SYMFONY_TOOLBAR_SELECTOR);
		if (!wrapper) return;
		this._observe(wrapper);
		this._placeBefore(wrapper, ref);
	}

	/**
	 * Height reserved at the viewport bottom by external chrome — currently the
	 * Symfony web profiler toolbar (its clearer height, 0 when absent or
	 * collapsed). Counted into `--iikiti-bars-bottom` so bottom bars, rails and
	 * notifications never sit under it.
	 *
	 * @returns {number}
	 */
	_externalBottom() {
		const clearer = document.querySelector(SYMFONY_CLEARER_SELECTOR);
		if (!clearer) return 0;
		return Math.max(0, Math.round(clearer.getBoundingClientRect().height));
	}

	/** Full re-layout: DOM slotting + sticky offsets + CSS var totals. */
	_apply() {
		const body = document.body;
		if (!body) return;

		// First non-bar body child — all leading stack slots go before it.
		const firstContent = [...body.children].find((ch) => !this._isManaged(ch));

		const tops = this._barsFor('top');
		const lefts = this._barsFor('left');
		const rights = this._barsFor('right');
		// Leading stack: top bars then rails, in stack order, before content.
		// Placed right-to-left with an in-place check so already-correct bars
		// are not detached/reinserted on every re-layout.
		const leading = [...tops, ...lefts, ...rights];
		for (let i = leading.length - 1; i >= 0; i--) {
			this._placeBefore(leading[i].el, leading[i + 1]?.el ?? firstContent ?? null);
		}
		// Bottom bars: closest-to-edge (lowest order) ends up last in the DOM.
		const bottoms = this._barsFor('bottom');
		const bottomDomOrder = [...bottoms].reverse();
		for (let i = bottomDomOrder.length - 1; i >= 0; i--) {
			this._placeBefore(bottomDomOrder[i].el, bottomDomOrder[i + 1]?.el ?? null);
		}

		// Keep the Symfony WebProfiler toolbar at the end of the flow (before
		// our bottom bars) so its clearer reserves space at the page bottom
		// instead of pushing content down mid-flow, and count the fixed bar as
		// bottom chrome.
		this._syncWebProfiler(bottomDomOrder[0]?.el ?? null);
		const externalBottom = this._externalBottom();

		const heights = new Map();
		const widths = new Map();
		for (const bar of [...tops, ...bottoms]) {
			heights.set(bar, this._height(bar, bar.el.getBoundingClientRect().height));
		}
		for (const bar of [...lefts, ...rights]) {
			widths.set(bar, this._height(bar, bar.el.getBoundingClientRect().width));
		}

		const totals = { top: 0, right: 0, bottom: 0, left: 0 };

		// Rail width totals first: top/bottom stack span the full viewport
		// width by cancelling this reserve, and rails pin between the stacks.
		for (const bar of lefts) totals.left += widths.get(bar) || 0;
		for (const bar of rights) totals.right += widths.get(bar) || 0;

		// Top stack: sticky offsets accumulate from the viewport edge downward.
		let acc = 0;
		for (const bar of tops) {
			this._applyTop(bar, acc, totals.left, totals.right);
			acc += heights.get(bar) || 0;
		}
		totals.top = acc;

		// Bottom stack: accumulate from the bottom edge outward, starting above
		// any external bottom chrome (the web profiler toolbar).
		acc = externalBottom;
		for (const bar of bottoms) {
			this._applyBottom(bar, acc, totals.left, totals.right);
			acc += heights.get(bar) || 0;
		}
		totals.bottom = acc;

		// Rails: fixed, spanning between the top and bottom stacks (numeric
		// offsets, not the CSS vars — vars lag until the end of the pass).
		acc = 0;
		for (const bar of lefts) {
			this._applyRail(bar, 'left', acc, totals.top, totals.bottom);
			acc += widths.get(bar) || 0;
		}

		acc = 0;
		for (const bar of rights) {
			this._applyRail(bar, 'right', acc, totals.top, totals.bottom);
			acc += widths.get(bar) || 0;
		}

		const rootStyle = document.documentElement.style;
		for (const side of SIDES) rootStyle.setProperty(SIDE_VAR[side], `${Math.round(totals[side])}px`);

		const payload = { sides: totals };
		for (const fn of [...this._changeListeners]) fn(payload);
		events.emit('iikiti:bars:change', payload);
	}

	/**
	 * @param {BarRecord} bar
	 * @param {number} offsetSum offset from the viewport edge (bars closer to it)
	 * @param {number} railLeft px reserved by left rails (cancelled via margin)
	 * @param {number} railRight px reserved by right rails (cancelled via margin)
	 */
	_applyTop(bar, offsetSum, railLeft, railRight) {
		bar.el.style.position = bar.sticky ? 'sticky' : '';
		bar.el.style.top = bar.sticky ? `${Math.round(offsetSum)}px` : '';
		bar.el.style.bottom = '';
		this._spanFullWidth(bar, railLeft, railRight);
	}

	/**
	 * @param {BarRecord} bar
	 * @param {number} offsetSum
	 * @param {number} railLeft px reserved by left rails (cancelled via margin)
	 * @param {number} railRight px reserved by right rails (cancelled via margin)
	 */
	_applyBottom(bar, offsetSum, railLeft, railRight) {
		bar.el.style.position = bar.sticky ? 'sticky' : '';
		bar.el.style.top = '';
		bar.el.style.bottom = bar.sticky ? `${Math.round(offsetSum)}px` : '';
		this._spanFullWidth(bar, railLeft, railRight);
	}

	/**
	 * Top/bottom stack bars span the full viewport width: the UI stylesheet
	 * pads `<body>` inline by the rail reserve (so content flows beside it),
	 * which would otherwise inset the bars' own flow width. Steps the bar
	 * back over that padding with negative inline margins so the header runs
	 * across the top of the rails (and the footer across their bottom).
	 *
	 * @param {BarRecord} bar
	 * @param {number} railLeft
	 * @param {number} railRight
	 */
	_spanFullWidth(bar, railLeft, railRight) {
		bar.el.style.marginInlineStart = `${-Math.round(railLeft)}px`;
		bar.el.style.marginInlineEnd = `${-Math.round(railRight)}px`;
	}

	/**
	 * @param {BarRecord} bar
	 * @param {'left'|'right'} side
	 * @param {number} offsetSum
	 * @param {number} topTotal px reserved above the rail (the top stack)
	 * @param {number} bottomTotal px reserved below the rail
	 */
	_applyRail(bar, side, offsetSum, topTotal, bottomTotal) {
		const offset = `${Math.round(offsetSum)}px`;
		bar.el.style.position = 'fixed';
		bar.el.style.top = `${Math.round(topTotal)}px`;
		bar.el.style.bottom = `${Math.round(bottomTotal)}px`;
		bar.el.style.height = '';
		if (side === 'left') {
			bar.el.style.right = '';
			bar.el.style.left = offset;
		} else {
			bar.el.style.left = '';
			bar.el.style.right = offset;
		}
	}

	/** Remove only what the manager wrote. @param {BarRecord} bar */
	_clearManagedStyles(bar) {
		bar.el.classList.remove('iikiti-bar');
		delete bar.el.dataset.iikitiBarSide;
		delete bar.el.dataset.iikitiBarId;
		bar.el.style.position = '';
		bar.el.style.top = '';
		bar.el.style.bottom = '';
		bar.el.style.left = '';
		bar.el.style.right = '';
		bar.el.style.marginInlineStart = '';
		bar.el.style.marginInlineEnd = '';
	}
}

export const bars = new BarsManager();
export { BarsManager };
