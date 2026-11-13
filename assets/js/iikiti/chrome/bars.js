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
 *    space via the `--theme-bars-left/right` body padding.
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
 *    bottom) and its height is counted into `--theme-bars-bottom` so nothing we
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
	top: '--theme-bars-top',
	right: '--theme-bars-right',
	bottom: '--theme-bars-bottom',
	left: '--theme-bars-left',
};
const DEFAULT_RAIL_WIDTH = 220;
const DEFAULT_MIN_SIZE = 36;

// Symfony WebProfilerBundle toolbar (development only). The wrapper holds a
// static `.sf-toolbar-clearer` spacer plus the fixed bar pinned to the
// viewport bottom; see `_syncWebProfiler`/`_externalBottom`.
const SYMFONY_TOOLBAR_SELECTOR = '.sf-toolbar';
const SYMFONY_CLEARER_SELECTOR = '.sf-toolbar-clearer';

const num = (v, fallback) => (Number.isFinite(Number(v)) ? Number(v) : fallback);
const validIncludePolicies = new Set(['auto', 'include', 'exclude']);
const validCollapsePolicies = new Set(['auto', 'none', 'flow']);

export function normalizeIncludeInLayout(value) {
	return validIncludePolicies.has(value) ? value : 'auto';
}

function normalizeCollapse(value) {
	return validCollapsePolicies.has(value) ? value : 'none';
}

function nonNegativeSize(value) {
	return Math.max(0, Math.round(Number(value) || 0));
}

/**
 * Resolve the current bar measurement independently from DOM positioning.
 * @param {object} bar
 * @param {{width?:number,height?:number}} rect
 * @param {boolean} cssHidden
 */
export function resolveBarMeasurement(bar, rect, cssHidden = false) {
	const currentSize = {
		width: nonNegativeSize(rect?.width),
		height: nonNegativeSize(rect?.height),
	};
	const visible = bar.visible !== false && !cssHidden;
	bar.lastMeasuredSize ??= { width: 0, height: 0 };
	if (visible) {
		if (currentSize.width > 0) bar.lastMeasuredSize.width = currentSize.width;
		if (currentSize.height > 0) bar.lastMeasuredSize.height = currentSize.height;
	}
	const policy = normalizeIncludeInLayout(bar.includeInLayout);
	const autoEligible = visible && bar.mode === 'stack' && !bar.authoredOverlay;
	const included = policy === 'include' || (policy === 'auto' && autoEligible);
	const size = visible ? currentSize : bar.lastMeasuredSize;
	return {
		visible,
		included,
		width: currentSize.width,
		height: currentSize.height,
		lastMeasuredSize: { ...bar.lastMeasuredSize },
		contributionSize: included ? { ...size } : { width: 0, height: 0 },
	};
}

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
		this._stateRafId = 0;
		/** @type {Set<Function>} */
		this._changeListeners = new Set();
		this._pendingChanges = [];
		this._lastTotals = { top: 0, right: 0, bottom: 0, left: 0 };
		this._lastApplied = false;
		this._boundScroll = () => this._scheduleStateCheck();
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
	 * @param {string} [opts.type]
	 * @param {'auto'|'include'|'exclude'} [opts.includeInLayout]
	 * @param {'auto'|'none'|'flow'} [opts.collapse]
	 * @param {boolean} [opts.frameworkManaged]
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
			if (existing) {
				this._configureExisting(existing, opts);
				return this._public(existing);
			}
		}

		let id = opts.id || ds.iikitiBarId || `iikiti-bar-${++this._seq}`;
		while (this._bars.has(id)) id = `${id}-${++this._seq}`;

		const mode = /** @type {'stack'|'fixed'|'absolute'} */ (
			['stack', 'fixed', 'absolute'].includes(opts.mode ?? ds.iikitiBarMode)
				? (opts.mode ?? ds.iikitiBarMode)
				: 'stack'
		);
		const authoredPosition = el ? this._computedStyle(el).position : '';
		const authoredOverlay = mode === 'stack' && ['fixed', 'absolute'].includes(authoredPosition);
		const type = opts.type || ds.iikitiBarType || ds.iikitiComponent || ds.component || 'bar';
		const bar = {
			id,
			el: el || this._createSlot(/** @type {'top'|'right'|'bottom'|'left'} */ (side), id),
			side: /** @type {'top'|'right'|'bottom'|'left'} */ (side),
			mode,
			type,
			authoredPosition,
			authoredOverlay,
			order: num(opts.order ?? ds.iikitiBarOrder, 0),
			sticky: opts.sticky !== undefined ? opts.sticky === true : ds.iikitiBarSticky === 'true',
			stuck: false,
			stickyOffset: 0,
			resizable: opts.resizable !== undefined ? opts.resizable === true : ds.iikitiBarResizable === 'true',
			includeInLayout: normalizeIncludeInLayout(opts.includeInLayout ?? ds.iikitiBarIncludeInLayout),
			collapse: normalizeCollapse(opts.collapse ?? ds.iikitiBarCollapse),
			frameworkManaged: opts.frameworkManaged === true,
			visible: opts.visible !== false,
			lastMeasuredSize: { width: 0, height: 0 },
			changeListeners: new Set(),
			flowTimeout: 0,
			flowRaf: 0,
			flowTransitionHandler: null,
			sentinel: null,
			savedStyles: null,
			minSize: num(opts.minSize ?? ds.iikitiBarMinSize, DEFAULT_MIN_SIZE),
			maxSize: num(opts.maxSize ?? ds.iikitiBarMaxSize, NaN),
			seq: ++this._seq,
			slotOwned: !el,
			resizeDestroy: () => {},
		};
		bar.savedStyles = this._saveStyles(bar.el);
		bar.originalAttributes = {
			managed: bar.el.getAttribute('data-iikiti-bar-managed'),
			side: bar.el.getAttribute('data-iikiti-bar-side'),
			id: bar.el.getAttribute('data-iikiti-bar-id'),
			ariaHidden: bar.el.getAttribute('aria-hidden'),
			inert: 'inert' in bar.el ? bar.el.inert : undefined,
			hadBarClass: bar.el.classList.contains('iikiti-bar'),
			hadHiddenClass: bar.el.classList.contains('iikiti-hidden'),
		};

		this._bars.set(id, bar);
		this._byElement.set(bar.el, id);
		this._queueChange(bar, 'registered', false, true);

		bar.el.dataset.iikitiBarManaged = 'true';
		bar.el.dataset.iikitiBarSide = bar.side;
		if (!bar.visible || this._hasHiddenStyle(bar.el)) bar.visible = false;
		const initialMeasurement = this._measure(bar);
		bar.lastVisible = initialMeasurement.visible;
		bar.lastIncluded = initialMeasurement.included;
		bar.lastNotifiedSize = { width: initialMeasurement.width, height: initialMeasurement.height };
		bar.lastPosition = authoredPosition;
		if (bar.sticky && ['top', 'bottom'].includes(bar.side) && this._positionsBar(bar)) {
			this._createSentinel(bar);
		}

		if (this._positionsBar(bar)) bar.el.classList.add('iikiti-bar');
		if (bar.el.id) bar.el.dataset.iikitiBarId = bar.id;
		this._observe(bar.el, bar);
		if (!bar.visible && this._usesFlowCollapse(bar) && bar.flowState !== 'hidden') {
			this._setFlowVisibility(bar, false, { animate: false });
		}

		if ((bar.side === 'left' || bar.side === 'right') && this._positionsBar(bar)) {
			this._initRailSize(bar, opts, ds);
		}

		this._updateResizeHandle(bar);

		this._schedule();
		return this._public(bar);
	}

	_configureExisting(bar, opts) {
		let shouldRelayout = false;
		if (opts.type && bar.type !== opts.type) bar.type = opts.type;
		if (opts.frameworkManaged === true) {
			bar.frameworkManaged = true;
			bar.collapse = normalizeCollapse(opts.collapse ?? 'auto');
		}
		if (opts.includeInLayout !== undefined) {
			bar.includeInLayout = normalizeIncludeInLayout(opts.includeInLayout);
		}
		const nextSide = SIDES_SET.has(opts.side) ? opts.side : bar.side;
		if (nextSide !== bar.side) {
			this._destroySentinel(bar);
			bar.side = nextSide;
			bar.el.dataset.iikitiBarSide = nextSide;
			if (bar.sticky && ['top', 'bottom'].includes(nextSide) && this._positionsBar(bar)) {
				this._createSentinel(bar);
			}
			shouldRelayout = true;
		}
		if (Number.isFinite(Number(opts.order)) && bar.order !== Number(opts.order)) {
			bar.order = Number(opts.order);
			shouldRelayout = true;
		}
		const nextMode = ['stack', 'fixed', 'absolute'].includes(opts.mode) ? opts.mode : bar.mode;
		if (nextMode !== bar.mode) {
			const wasPositioned = this._positionsBar(bar);
			bar.mode = nextMode;
			bar.authoredOverlay = nextMode === 'stack' && ['fixed', 'absolute'].includes(bar.authoredPosition);
			const isPositioned = this._positionsBar(bar);
			if (wasPositioned && !isPositioned) {
				bar.resizeDestroy?.();
				bar.resizeDestroy = () => {};
				bar.resizeAttached = false;
				this._destroySentinel(bar);
				if (!bar.originalAttributes?.hadBarClass) bar.el.classList.remove('iikiti-bar');
				this._restoreStyles(bar);
			} else if (!wasPositioned && isPositioned) {
				bar.el.classList.add('iikiti-bar');
				if (bar.sticky && ['top', 'bottom'].includes(bar.side)) this._createSentinel(bar);
			}
			shouldRelayout = true;
		}
		if ((bar.side === 'left' || bar.side === 'right') && this._positionsBar(bar)) {
			this._initRailSize(bar, opts, bar.el.dataset);
		}
		this._updateResizeHandle(bar, opts.resizable);
		if (opts.sticky !== undefined && bar.sticky !== (opts.sticky === true)) {
			this.setSticky(bar.id, opts.sticky);
		}
		if (opts.visible !== undefined && bar.visible !== (opts.visible === true)) {
			this.setVisible(bar.id, opts.visible === true, { animate: false });
		}
		if (!bar.visible && this._usesFlowCollapse(bar)) {
			this._setFlowVisibility(bar, false, { animate: false });
		}
		if (shouldRelayout) this._schedule();
	}

	_updateResizeHandle(bar, resizable = bar.resizable) {
		bar.resizable = resizable === true;
		if (!bar.resizable || !this._positionsBar(bar)) {
			bar.resizeDestroy?.();
			bar.resizeDestroy = () => {};
			bar.resizeAttached = false;
			return;
		}
		if (bar.resizeAttached) return;
		const handle = createResizeHandle(bar.el, {
			edge: contentEdgeForSide(bar.side),
			min: bar.minSize,
			max: Number.isNaN(bar.maxSize) ? undefined : bar.maxSize,
			persistKey: `bar.${bar.id}.size`,
			className: 'iikiti-bar__resize-handle',
		});
		handle.applyPersisted();
		bar.resizeDestroy = handle.destroy;
		bar.resizeAttached = true;
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
		bar.mutationObserver?.disconnect();
		bar.resizeDestroy?.();
		this._finishFlow(bar, { hidden: false, restoreStyles: true });
		this._destroySentinel(bar);
		const removed = bar.el;
		if (bar.slotOwned && opts.removeSlot !== false) {
			bar.el.remove();
		} else {
			this._clearManagedStyles(bar);
		}
		this._bars.delete(id);
		this._byElement.delete(bar.el);
		this._queueChange(bar, 'unregistered', true, false);
		this._schedule();
		return removed;
	}

	/**
	 * Total reserved size (px) for a side, including explicit overlay inclusion
	 * and external bottom chrome.
	 * @param {'top'|'right'|'bottom'|'left'} side
	 * @returns {number}
	 */
	getOffset(side) {
		if (!SIDES_SET.has(side)) return 0;
		if (this._lastApplied) return this._lastTotals[side];
		return this._sideContribution(side) + (side === 'bottom' ? this._externalBottom() : 0);
	}

	/** Schedule an immediate geometry/style refresh. */
	refresh() {
		this._schedule();
	}

	/** @param {string} id @param {'auto'|'include'|'exclude'|boolean|null} policy */
	setIncluded(id, policy) {
		const bar = this._bars.get(id);
		if (!bar) return false;
		const next = policy === null ? 'auto'
			: policy === true ? 'include'
				: policy === false ? 'exclude'
					: normalizeIncludeInLayout(policy);
		if (bar.includeInLayout === next) return true;
		const previous = bar.includeInLayout;
		bar.includeInLayout = next;
		this._queueChange(bar, 'policy', previous, next);
		this._schedule();
		return true;
	}

	/** @param {string} id @param {boolean|null} visible */
	setVisible(id, visible, options = {}) {
		const bar = this._bars.get(id);
		if (!bar) return false;
		if (visible === null) visible = !this._hasHiddenStyle(bar.el);
		visible = visible === true;
		if (bar.visible === visible) return true;
		if (!visible) this._measure(bar, { ignoreVisibility: true });
		const previous = bar.lastVisible ?? bar.visible;
		bar.visible = visible;
		bar.lastVisible = visible;
		this._queueChange(bar, 'visible', previous, visible);
		if (this._usesFlowCollapse(bar)) this._setFlowVisibility(bar, visible, options);
		this._schedule();
		return true;
	}

	/** @param {string} id @param {boolean} sticky */
	setSticky(id, sticky) {
		const bar = this._bars.get(id);
		if (!bar) return false;
		sticky = sticky === true;
		if (bar.sticky === sticky) return true;
		const previous = bar.sticky;
		bar.sticky = sticky;
		this._queueChange(bar, 'sticky', previous, sticky);
		if (sticky && ['top', 'bottom'].includes(bar.side) && this._positionsBar(bar)) this._createSentinel(bar);
		else {
			this._destroySentinel(bar);
			this._setStuck(bar, false);
		}
		this._schedule();
		return true;
	}

	/**
	 * Registered bars. The legacy string/no-argument form keeps returning compact
	 * summaries; the options form returns live geometry snapshots.
	 * @param {'top'|'right'|'bottom'|'left'|object} [sideOrOptions]
	 */
	list(sideOrOptions) {
		if (sideOrOptions && typeof sideOrOptions === 'object') {
			const options = sideOrOptions;
			return [...this._bars.values()]
				.filter((bar) => !options.side || bar.side === options.side)
				.filter((bar) => !options.mode || bar.mode === options.mode)
				.filter((bar) => !options.type || bar.type === options.type)
				.filter((bar) => !options.position || this._positionMatches(bar, options.position))
				.map((bar) => this._snapshot(bar))
				.filter((bar) => options.includeExcluded === true || bar.included);
		}
		const side = sideOrOptions;
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
		window.addEventListener('scroll', this._boundScroll, { passive: true });
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
			collapse: bar.collapse,
			flowCollapse: this._usesFlowCollapse(bar),
			setIncluded: (policy) => this.setIncluded(bar.id, policy),
			setVisible: (visible, options) => this.setVisible(bar.id, visible, options),
			setSticky: (sticky) => this.setSticky(bar.id, sticky),
			refresh: () => this.refresh(),
			onChange: (fn) => this._onBarChange(bar, fn),
			destroy: () => this.unregister(bar.id),
		};
	}

	_computedStyle(el) {
		return typeof getComputedStyle === 'function' ? getComputedStyle(el) : el.style;
	}

	_saveStyles(el) {
		const properties = [
			'position', 'top', 'right', 'bottom', 'left', 'width', 'height',
			'marginInlineStart', 'marginInlineEnd', 'marginBlockStart', 'marginBlockEnd',
			'translate', 'opacity', 'transition',
		];
		return Object.fromEntries(properties.map((property) => [property, el.style[property] || '']));
	}

	_restoreStyles(bar) {
		for (const [property, value] of Object.entries(bar.savedStyles || {})) {
			bar.el.style[property] = value;
		}
	}

	_hasHiddenStyle(el) {
		if (!el || el.hidden || el.classList?.contains('iikiti-hidden')) return true;
		if (el.getAttribute?.('aria-hidden') === 'true') return true;
		const style = this._computedStyle(el);
		return style.display === 'none' || style.visibility === 'hidden' || style.visibility === 'collapse';
	}

	_positionsBar(bar) {
		return bar.mode === 'stack' && !bar.authoredOverlay;
	}

	_usesFlowCollapse(bar) {
		return bar.collapse === 'flow' || (bar.collapse === 'auto' && bar.frameworkManaged);
	}

	_flowDirection(bar) {
		if (['header', 'footer'].includes(bar.type)) return '0 0';
		if (bar.type === 'sidebar' && bar.el.classList.contains('iikiti-sidebar--mobile')) return '0 0';
		if (bar.side === 'top') return '0 -100%';
		if (bar.side === 'bottom') return '0 100%';
		if (bar.side === 'left') return '-100% 0';
		return '100% 0';
	}

	_restoreFlowMargin(bar) {
		if (!bar.flowMarginOriginal) return;
		bar.el.style[bar.flowMarginOriginal.property] = bar.flowMarginOriginal.value;
		bar.flowMarginOriginal = null;
		events.emit('iikiti:bars:flow-shift');
	}

	_collapseFlowMargin(bar) {
		if (!this._positionsBar(bar) || (bar.side !== 'top' && bar.side !== 'bottom')) return;
		const property = bar.side === 'top' ? 'marginBlockEnd' : 'marginBlockStart';
		if (!bar.flowMarginOriginal) {
			bar.flowMarginOriginal = { property, value: bar.el.style[property] || '' };
		}
		const computed = this._computedStyle(bar.el);
		const marginStart = Number.parseFloat(computed.marginBlockStart) || 0;
		const marginEnd = Number.parseFloat(computed.marginBlockEnd) || 0;
		const otherMargin = bar.side === 'top' ? marginStart : marginEnd;
		const contribution = bar.lastMeasuredSize.height + otherMargin;
		bar.el.style[property] = `${-Math.round(contribution)}px`;
		events.emit('iikiti:bars:flow-shift');
	}

	_flowDuration(bar) {
		const style = this._computedStyle(bar.el);
		const toMilliseconds = (value) => value.trim().split(',').map((part) => {
			const trimmed = part.trim();
			const amount = Number.parseFloat(trimmed) || 0;
			return trimmed.endsWith('ms') ? amount : amount * 1000;
		});
		const durations = toMilliseconds(style.transitionDuration || '0s');
		const delays = toMilliseconds(style.transitionDelay || '0s');
		return Math.max(0, ...durations.map((duration, index) => duration + (delays[index] ?? delays[0] ?? 0))) + 50;
	}

	_cancelFlowTransition(bar) {
		clearTimeout(bar.flowTimeout);
		bar.flowTimeout = 0;
		if (bar.flowRaf) cancelAnimationFrame(bar.flowRaf);
		bar.flowRaf = 0;
		if (bar.flowTransitionHandler) {
			bar.el.removeEventListener('transitionend', bar.flowTransitionHandler);
			bar.flowTransitionHandler = null;
		}
	}

	_setFlowVisibility(bar, visible, options = {}) {
		this._cancelFlowTransition(bar);
		const el = bar.el;
		const reducedMotion = typeof matchMedia === 'function'
			&& matchMedia('(prefers-reduced-motion: reduce)').matches;
		const animate = options.animate ?? !reducedMotion;
		el.setAttribute('aria-hidden', String(!visible));
		if ('inert' in el) el.inert = !visible;
		el.classList.add('iikiti-bar--flow-transition');

		if (visible) {
			const wasFullyHidden = bar.flowState === 'hidden' || el.classList.contains('iikiti-hidden');
			el.classList.remove('iikiti-hidden');
			this._restoreFlowMargin(bar);
			if (!animate) {
				this._finishFlow(bar, { hidden: false });
				bar.flowState = 'visible';
				return;
			}
			if (wasFullyHidden) {
				el.style.translate = this._flowDirection(bar);
				el.style.opacity = '0';
				void el.getBoundingClientRect().width;
			}
			bar.flowState = 'showing';
			bar.flowRaf = requestAnimationFrame(() => {
				bar.flowRaf = 0;
				if (!bar.visible) return;
				el.style.translate = '0 0';
				el.style.opacity = bar.savedStyles?.opacity ?? '';
			});
		} else {
			this._collapseFlowMargin(bar);
			if (!animate) {
				this._finishFlow(bar, { hidden: true });
				bar.flowState = 'hidden';
				return;
			}
			bar.flowState = 'hiding';
			el.style.translate = this._flowDirection(bar);
			el.style.opacity = '0';
		}

		bar.flowTransitionHandler = (event) => {
			if (event.target !== el || !['opacity', 'translate', 'transform'].includes(event.propertyName)) return;
			this._finishFlow(bar, { hidden: !bar.visible });
			bar.flowState = bar.visible ? 'visible' : 'hidden';
		};
		el.addEventListener('transitionend', bar.flowTransitionHandler);
		bar.flowTimeout = setTimeout(() => {
			this._finishFlow(bar, { hidden: !bar.visible });
			bar.flowState = bar.visible ? 'visible' : 'hidden';
		}, this._flowDuration(bar));
	}

	_finishFlow(bar, options = {}) {
		clearTimeout(bar.flowTimeout);
		bar.flowTimeout = 0;
		if (bar.flowRaf) cancelAnimationFrame(bar.flowRaf);
		bar.flowRaf = 0;
		if (bar.flowTransitionHandler) {
			bar.el.removeEventListener('transitionend', bar.flowTransitionHandler);
			bar.flowTransitionHandler = null;
		}
		bar.el.classList.remove('iikiti-bar--flow-transition');
		if (options.hidden) {
			bar.el.classList.add('iikiti-hidden');
		} else {
			bar.el.classList.remove('iikiti-hidden');
			this._restoreFlowMargin(bar);
		}
		bar.el.style.translate = bar.savedStyles?.translate ?? '';
		bar.el.style.opacity = bar.savedStyles?.opacity ?? '';
		if (options.restoreStyles) this._restoreStyles(bar);
	}

	_measure(bar, options = {}) {
		const rect = bar.el.getBoundingClientRect();
		const visible = bar.visible;
		if (options.ignoreVisibility) bar.visible = true;
		const result = resolveBarMeasurement(bar, rect, options.ignoreVisibility ? false : this._hasHiddenStyle(bar.el));
		bar.visible = visible;
		return result;
	}

	_sideContribution(side) {
		const vertical = side === 'top' || side === 'bottom';
		return this._barsForSide(side).reduce((total, bar) => {
			const measurement = this._measure(bar);
			return total + (vertical ? measurement.contributionSize.height : measurement.contributionSize.width);
		}, 0);
	}

	_positionMatches(bar, filter) {
		const effective = this._computedStyle(bar.el).position || '';
		return bar.authoredPosition === filter || effective === filter;
	}

	_snapshot(bar) {
		const rect = bar.el.getBoundingClientRect();
		const measurement = this._measure(bar);
		return {
			id: bar.id,
			type: bar.type,
			side: bar.side,
			mode: bar.mode,
			position: this._computedStyle(bar.el).position || '',
			authoredPosition: bar.authoredPosition,
			width: measurement.width,
			height: measurement.height,
			lastMeasuredSize: measurement.lastMeasuredSize,
			contributionSize: measurement.contributionSize,
			rect: {
				x: rect.x,
				y: rect.y,
				top: rect.top,
				right: rect.right,
				bottom: rect.bottom,
				left: rect.left,
				width: rect.width,
				height: rect.height,
			},
			sticky: bar.sticky,
			stuck: bar.stuck,
			visible: measurement.visible,
			included: measurement.included,
			element: bar.el,
		};
	}

	_queueChange(bar, property, previous, current) {
		if (Object.is(previous, current)) return;
		this._pendingChanges.push({ bar, id: bar.id, element: bar.el, property, previous, current });
	}

	_onBarChange(bar, fn) {
		if (typeof fn !== 'function') return () => {};
		bar.changeListeners.add(fn);
		return () => bar.changeListeners.delete(fn);
	}

	_dispatchChanges(totals = this._lastTotals) {
		const queued = this._pendingChanges.splice(0);
		const changes = queued.map((change) => ({
			id: change.id,
			element: change.element,
			property: change.property,
			previous: change.previous,
			current: change.current,
			bar: this._bars.has(change.id) ? this._snapshot(change.bar) : null,
		}));
		for (let index = 0; index < changes.length; index++) {
			const bar = queued[index].bar;
			for (const fn of [...bar.changeListeners]) fn(changes[index]);
			if (!this._bars.has(changes[index].id)) bar.changeListeners.clear();
		}
		const payload = { sides: { ...totals }, changes };
		for (const fn of [...this._changeListeners]) fn(payload);
		events.emit('iikiti:bars:change', payload);
	}

	_createSentinel(bar) {
		if (bar.sentinel || !this._positionsBar(bar) || !['top', 'bottom'].includes(bar.side)) return;
		const sentinel = document.createElement('span');
		sentinel.dataset.iikitiBarSentinel = 'true';
		sentinel.setAttribute('aria-hidden', 'true');
		Object.assign(sentinel.style, {
			display: 'block', width: '0', height: '0', margin: '0', padding: '0',
			border: '0', overflow: 'hidden', pointerEvents: 'none',
		});
		bar.sentinel = sentinel;
		const parent = bar.el.parentNode;
		if (parent) {
			if (bar.side === 'top') parent.insertBefore(sentinel, bar.el);
			else parent.insertBefore(sentinel, bar.el.nextSibling);
		}
	}

	_destroySentinel(bar) {
		bar.sentinel?.remove();
		bar.sentinel = null;
	}

	_scheduleStateCheck() {
		if (this._stateRafId) return;
		this._stateRafId = requestAnimationFrame(() => {
			this._stateRafId = 0;
			this._checkStuck();
		});
	}

	_checkStuck(dispatch = true) {
		const viewportHeight = window.innerHeight;
		for (const bar of this._bars.values()) {
			if (!bar.sticky || !bar.visible || !bar.sentinel || !this._positionsBar(bar)) {
				this._setStuck(bar, false);
				continue;
			}
			const rect = bar.el.getBoundingClientRect();
			const sentinel = bar.sentinel.getBoundingClientRect();
			const edge = bar.side === 'top' ? bar.stickyOffset : viewportHeight - bar.stickyOffset;
			const atEdge = bar.side === 'top'
				? Math.abs(rect.top - edge) <= 1.5 && sentinel.top < edge - 0.5
				: Math.abs(rect.bottom - edge) <= 1.5 && sentinel.bottom > edge + 0.5;
			this._setStuck(bar, atEdge);
		}
		if (dispatch && !this._rafId && this._pendingChanges.length) this._dispatchChanges();
	}

	_setStuck(bar, stuck) {
		if (bar.stuck === stuck) return;
		const previous = bar.stuck;
		bar.stuck = stuck;
		this._queueChange(bar, 'stuck', previous, stuck);
	}

	/**
	 * @param {'top'|'right'|'bottom'|'left'} side
	 * @returns {BarRecord[]} registered stack bars of that side, stacking order
	 */
	_barsFor(side) {
		return [...this._bars.values()]
			.filter((b) => b.side === side && this._positionsBar(b))
			.sort((a, b) => a.order - b.order || a.seq - b.seq);
	}

	/** @param {'top'|'right'|'bottom'|'left'} side */
	_barsForSide(side) {
		return [...this._bars.values()]
			.filter((bar) => bar.side === side)
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
	_observe(el, bar = null) {
		this._ro ??= new ResizeObserver(() => this._schedule());
		this._ro.observe(el);
		if (bar && typeof MutationObserver === 'function') {
			bar.mutationObserver = new MutationObserver(() => this._schedule());
			bar.mutationObserver.observe(el, {
				attributes: true,
				attributeFilter: ['class', 'hidden', 'aria-hidden'],
			});
		}
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
		return el instanceof HTMLElement
			&& (el.dataset.iikitiBarManaged === 'true' || el.dataset.iikitiBarSentinel === 'true');
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
	 * collapsed). Counted into `--theme-bars-bottom` so bottom bars, rails and
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
		if (this._rafId) {
			cancelAnimationFrame(this._rafId);
			this._rafId = 0;
		}
		const body = document.body;
		if (!body) return;

		// First non-bar body child — all leading stack slots go before it.
		const firstContent = [...body.children].find((ch) => !this._isManaged(ch));
		const tops = this._barsForSide('top');
		const lefts = this._barsForSide('left');
		const rights = this._barsForSide('right');
		// Leading stack: top bars then rails, in stack order, before content.
		// Placed right-to-left with an in-place check so already-correct bars
		// are not detached/reinserted on every re-layout.
		const leading = [
			...tops.filter((bar) => this._positionsBar(bar)).flatMap((bar) => bar.sentinel ? [bar.sentinel, bar.el] : [bar.el]),
			...lefts.filter((bar) => this._positionsBar(bar)).map((bar) => bar.el),
			...rights.filter((bar) => this._positionsBar(bar)).map((bar) => bar.el),
		];
		for (let i = leading.length - 1; i >= 0; i--) {
			this._placeBefore(leading[i], leading[i + 1] ?? firstContent ?? null);
		}
		// Bottom bars: closest-to-edge (lowest order) ends up last in the DOM.
		const bottoms = this._barsForSide('bottom');
		const bottomDomOrder = [...bottoms].reverse();
		const bottomEntries = bottomDomOrder
			.filter((bar) => this._positionsBar(bar))
			.flatMap((bar) => bar.sentinel ? [bar.el, bar.sentinel] : [bar.el]);
		for (let i = bottomEntries.length - 1; i >= 0; i--) {
			this._placeBefore(bottomEntries[i], bottomEntries[i + 1] ?? null);
		}

		// Keep the Symfony WebProfiler toolbar at the end of the flow (before
		// our bottom bars) so its clearer reserves space at the page bottom
		// instead of pushing content down mid-flow, and count the fixed bar as
		// bottom chrome.
		this._syncWebProfiler(bottomDomOrder[0]?.el ?? null);
		const externalBottom = this._externalBottom();

		const measurements = new Map();
		for (const bar of this._bars.values()) {
			const measurement = this._measure(bar);
			measurements.set(bar, measurement);
			if (bar.lastVisible !== undefined && bar.lastVisible !== measurement.visible) {
				this._queueChange(bar, 'visible', bar.lastVisible, measurement.visible);
			}
			bar.lastVisible = measurement.visible;
			if (bar.lastIncluded !== undefined && bar.lastIncluded !== measurement.included) {
				this._queueChange(bar, 'included', bar.lastIncluded, measurement.included);
			}
			bar.lastIncluded = measurement.included;
			for (const dimension of ['width', 'height']) {
				const current = measurement[dimension];
				const previous = bar.lastNotifiedSize?.[dimension];
				if (previous !== undefined && current !== previous) {
					this._queueChange(bar, dimension, previous, current);
				}
				bar.lastNotifiedSize ??= {};
				bar.lastNotifiedSize[dimension] = current;
			}
		}

		const totals = { top: 0, right: 0, bottom: 0, left: 0 };

		// Rail width totals first: top/bottom stack span the full viewport
		// width by cancelling this reserve, and rails pin between the stacks.
		for (const bar of lefts) totals.left += measurements.get(bar).contributionSize.width;
		for (const bar of rights) totals.right += measurements.get(bar).contributionSize.width;

		// Top stack: sticky offsets accumulate from the viewport edge downward.
		let acc = 0;
		for (const bar of tops) {
			bar.stickyOffset = acc;
			if (this._positionsBar(bar)) this._applyTop(bar, acc, totals.left, totals.right);
			acc += measurements.get(bar).contributionSize.height;
		}
		totals.top = acc;

		// Bottom stack: accumulate from the bottom edge outward, starting above
		// any external bottom chrome (the web profiler toolbar).
		acc = externalBottom;
		for (const bar of bottoms) {
			bar.stickyOffset = acc;
			if (this._positionsBar(bar)) this._applyBottom(bar, acc, totals.left, totals.right);
			acc += measurements.get(bar).contributionSize.height;
		}
		totals.bottom = acc;

		// Rails: fixed, spanning between the top and bottom stacks (numeric
		// offsets, not the CSS vars — vars lag until the end of the pass).
		acc = 0;
		for (const bar of lefts) {
			if (this._positionsBar(bar)) this._applyRail(bar, 'left', acc, totals.top, totals.bottom);
			acc += measurements.get(bar).contributionSize.width;
		}

		acc = 0;
		for (const bar of rights) {
			if (this._positionsBar(bar)) this._applyRail(bar, 'right', acc, totals.top, totals.bottom);
			acc += measurements.get(bar).contributionSize.width;
		}
		for (const bar of this._bars.values()) {
			const position = this._computedStyle(bar.el).position || '';
			if (bar.lastPosition !== undefined && bar.lastPosition !== position) {
				this._queueChange(bar, 'position', bar.lastPosition, position);
			}
			bar.lastPosition = position;
		}

		const rootStyle = document.documentElement.style;
		for (const side of SIDES) rootStyle.setProperty(SIDE_VAR[side], `${Math.round(totals[side])}px`);
		this._lastTotals = { ...totals };
		this._lastApplied = true;
		this._checkStuck(false);
		this._dispatchChanges(totals);
	}

	/**
	 * @param {BarRecord} bar
	 * @param {number} offsetSum offset from the viewport edge (bars closer to it)
	 * @param {number} railLeft px reserved by left rails (cancelled via margin)
	 * @param {number} railRight px reserved by right rails (cancelled via margin)
	 */
	_applyTop(bar, offsetSum, railLeft, railRight) {
		bar.el.style.position = bar.sticky ? 'sticky' : (bar.savedStyles?.position ?? '');
		bar.el.style.top = bar.sticky ? `${Math.round(offsetSum)}px` : (bar.savedStyles?.top ?? '');
		bar.el.style.bottom = bar.savedStyles?.bottom ?? '';
		this._spanFullWidth(bar, railLeft, railRight);
	}

	/**
	 * @param {BarRecord} bar
	 * @param {number} offsetSum
	 * @param {number} railLeft px reserved by left rails (cancelled via margin)
	 * @param {number} railRight px reserved by right rails (cancelled via margin)
	 */
	_applyBottom(bar, offsetSum, railLeft, railRight) {
		bar.el.style.position = bar.sticky ? 'sticky' : (bar.savedStyles?.position ?? '');
		bar.el.style.top = bar.savedStyles?.top ?? '';
		bar.el.style.bottom = bar.sticky ? `${Math.round(offsetSum)}px` : (bar.savedStyles?.bottom ?? '');
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
		if (!bar.originalAttributes?.hadBarClass) bar.el.classList.remove('iikiti-bar');
		if (bar.originalAttributes?.hadHiddenClass) bar.el.classList.add('iikiti-hidden');
		else bar.el.classList.remove('iikiti-hidden');
		bar.el.classList.remove('iikiti-bar--flow-transition');
		this._restoreStyles(bar);
		for (const [attribute, value] of [
			['data-iikiti-bar-managed', bar.originalAttributes?.managed],
			['data-iikiti-bar-side', bar.originalAttributes?.side],
			['data-iikiti-bar-id', bar.originalAttributes?.id],
			['aria-hidden', bar.originalAttributes?.ariaHidden],
		]) {
			if (value === null || value === undefined) bar.el.removeAttribute(attribute);
			else bar.el.setAttribute(attribute, value);
		}
		if (bar.originalAttributes?.inert !== undefined) bar.el.inert = bar.originalAttributes.inert;
	}
}

export const bars = new BarsManager();
export { BarsManager };
