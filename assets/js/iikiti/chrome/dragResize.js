/**
 * Pointer-based drag-to-resize helper (shared by the viewport bars manager
 * and the Svelte FloatingPanel component).
 *
 * The handle is an element placed on the edge that faces the content
 * (see `.iikiti-resize-handle` in ui.css for the orientation styles):
 *
 *   top bar    → data-edge="bottom"  (drag down to grow)
 *   bottom bar → data-edge="top"     (drag up to grow)
 *   left rail  → data-edge="right"   (drag right to grow)
 *   right rail → data-edge="left"    (drag left to grow)
 *   panel      → data-edge="corner"  (drag down-right to grow)
 *
 * bindResize attaches the pointer logic to an existing handle element;
 * createResizeHandle also creates the handle inside the target.
 *
 * Sizes can be persisted to localStorage via `persistKey` (JSON {w,h}).
 */

const STORE_PREFIX = 'iikiti.';

/**
 * @param {string} key
 * @returns {{w:number,h:number} | null}
 */
export function readPersistedSize(key) {
	if (!key) return null;
	try {
		const raw = localStorage.getItem(STORE_PREFIX + key);
		if (!raw) return null;
		const v = JSON.parse(raw);
		if (!v || typeof v !== 'object') return null;
		return {
			w: Number.isFinite(Number(v.w)) ? Number(v.w) : 0,
			h: Number.isFinite(Number(v.h)) ? Number(v.h) : 0,
		};
	} catch {
		return null;
	}
}

/**
 * @param {string} key
 * @param {{w:number|null,h:number|null}} size
 */
export function writePersistedSize(key, size) {
	if (!key) return;
	try {
		localStorage.setItem(STORE_PREFIX + key, JSON.stringify(size));
	} catch {
		/* storage unavailable (private mode/quota) — resizing stays ephemeral */
	}
}

/**
 * @param {string} edge
 * @returns {{x:boolean,y:boolean,xdir:1|-1,ydir:1|-1}} which axes move (+1 grows with pointer)
 */
function edgeSpec(edge) {
	switch (edge) {
		case 'bottom': return { x: false, y: true, xdir: 1, ydir: 1 };
		case 'top': return { x: false, y: true, xdir: 1, ydir: -1 };
		case 'right': return { x: true, y: false, xdir: 1, ydir: -1 };
		case 'left': return { x: true, y: false, xdir: -1, ydir: 1 };
		case 'corner': return { x: true, y: true, xdir: 1, ydir: 1 };
		default: return { x: false, y: true, xdir: 1, ydir: 1 };
	}
}

/**
 * @param {number} value
 * @param {number} min
 * @param {number} max
 */
const clamp = (value, min, max) => Math.min(Math.max(value, min), Math.max(min, max));

/**
 * Wire drag-to-resize pointer behaviour.
 *
 * @param {HTMLElement} handle element the user drags (pointer capture)
 * @param {HTMLElement} target element whose size changes
 * @param {object} [opts]
 * @param {'top'|'right'|'bottom'|'left'|'corner'} [opts.edge] which edge the
 *   handle sits on — decides the resized axis and growth direction
 * @param {number} [opts.min] pixel minimum for the resized axis
 * @param {number} [opts.minW] per-axis minimum override (width)
 * @param {number} [opts.minH] per-axis minimum override (height)
 * @param {number} [opts.max] pixel maximum applied to both axes (default
 *   viewport-relative: 60vw width / 40vh height)
 * @param {number} [opts.maxW] per-axis maximum override (width)
 * @param {number} [opts.maxH] per-axis maximum override (height)
 * @param {string} [opts.persistKey] localStorage key ('iikiti.' is prefixed)
 * @param {() => void} [opts.onStart]
 * @param {(w:number,h:number) => void} [opts.onResize]
 * @param {(w:number,h:number) => void} [opts.onEnd]
 * @returns {{ destroy(): void, applyPersisted(): void }}
 */
export function bindResize(handle, target, opts = {}) {
	if (!handle || !target) return { destroy() {}, applyPersisted() {} };

	const spec = edgeSpec(opts.edge ?? 'bottom');
	const min = Number.isFinite(Number(opts.min)) ? Number(opts.min) : 36;
	/** Per-axis minimum (defaults to `min`). */
	const minW = Number.isFinite(Number(opts.minW)) ? Number(opts.minW) : min;
	const minH = Number.isFinite(Number(opts.minH)) ? Number(opts.minH) : min;
	// Per-axis maxima: width defaults to 60vw, height to 40vh. `max` applies
	// to both axes when set (bars pass a single authored size); `maxW`/`maxH`
	// override one axis (the FloatingPanel corner handle resizes both).
	const sharedMax = Number.isFinite(Number(opts.max)) ? Number(opts.max) : null;
	const maxW = Number.isFinite(Number(opts.maxW))
		? Number(opts.maxW)
		: (sharedMax ?? window.innerWidth * 0.6);
	const maxH = Number.isFinite(Number(opts.maxH))
		? Number(opts.maxH)
		: (sharedMax ?? window.innerHeight * 0.4);

	let startX = 0;
	let startY = 0;
	let startW = 0;
	let startH = 0;
	let dragging = false;

	/** @param {number} px @param {'width'|'height'} dim */
	function setPx(px, dim) {
		target.style[dim] = `${Math.round(px)}px`;
	}

	function onPointerDown(/** @type {PointerEvent} */ e) {
		if (e.button !== 0 || dragging) return;
		e.preventDefault();
		dragging = true;
		const rect = target.getBoundingClientRect();
		startX = e.clientX;
		startY = e.clientY;
		startW = rect.width;
		startH = rect.height;
		handle.dataset.iikitiResizing = 'true';
		try {
			handle.setPointerCapture(e.pointerId);
		} catch {
			/* capture is best-effort */
		}
		handle.addEventListener('pointermove', onPointerMove);
		handle.addEventListener('pointerup', onPointerUp);
		handle.addEventListener('pointercancel', onPointerUp);
		opts.onStart?.();
	}

	function onPointerMove(/** @type {PointerEvent} */ e) {
		if (!dragging) return;
		if (spec.x) {
			setPx(clamp(startW + spec.xdir * (e.clientX - startX), minW, maxW), 'width');
		}
		if (spec.y) {
			setPx(clamp(startH + spec.ydir * (e.clientY - startY), minH, maxH), 'height');
		}
		if (opts.onResize) {
			// Read once; only when a consumer actually needs the size.
			const rect = target.getBoundingClientRect();
			opts.onResize(rect.width, rect.height);
		}
	}

	function onPointerUp(/** @type {PointerEvent} */ e) {
		if (!dragging) return;
		dragging = false;
		delete handle.dataset.iikitiResizing;
		handle.removeEventListener('pointermove', onPointerMove);
		handle.removeEventListener('pointerup', onPointerUp);
		handle.removeEventListener('pointercancel', onPointerUp);
		try {
			handle.releasePointerCapture(e.pointerId);
		} catch {
			/* already released */
		}
		if (opts.persistKey) {
			writePersistedSize(opts.persistKey, {
				w: spec.x ? target.getBoundingClientRect().width : null,
				h: spec.y ? target.getBoundingClientRect().height : null,
			});
		}
		opts.onEnd?.(target.getBoundingClientRect().width, target.getBoundingClientRect().height);
	}

	handle.addEventListener('pointerdown', onPointerDown);

	return {
		destroy() {
			handle.removeEventListener('pointerdown', onPointerDown);
			handle.removeEventListener('pointermove', onPointerMove);
			handle.removeEventListener('pointerup', onPointerUp);
			handle.removeEventListener('pointercancel', onPointerUp);
		},
		/** Apply a previously persisted size to the target immediately. */
		applyPersisted() {
			const stored = readPersistedSize(opts.persistKey);
			if (!stored) return;
			if (spec.x && stored.w) setPx(clamp(stored.w, minW, maxW), 'width');
			if (spec.y && stored.h) setPx(clamp(stored.h, minH, maxH), 'height');
		},
	};
}

/**
 * Create a `.iikiti-resize-handle` child inside `target` and wire it.
 *
 * @param {HTMLElement} target
 * @param {object} opts passed to {@see bindResize}; `edge` is required.
 * @returns {{ el: HTMLElement, destroy(): void, applyPersisted(): void }}
 */
export function createResizeHandle(target, opts = {}) {
	const edge = opts.edge ?? 'bottom';
	const handle = document.createElement('div');
	handle.className = 'iikiti-resize-handle' + (opts.className ? ` ${opts.className}` : '');
	handle.setAttribute('data-edge', edge);
	handle.setAttribute('aria-hidden', 'true');
	target.append(handle);
	const bound = bindResize(handle, target, { ...opts, edge });
	return {
		el: handle,
		destroy() {
			bound.destroy();
			handle.remove();
		},
		applyPersisted: bound.applyPersisted,
	};
}

/**
 * Handle edge facing the content for a given viewport side.
 *
 * @param {'top'|'right'|'bottom'|'left'} side
 * @returns {'top'|'right'|'bottom'|'left'}
 */
export function contentEdgeForSide(side) {
	switch (side) {
		case 'top': return 'bottom';
		case 'bottom': return 'top';
		case 'left': return 'right';
		default: return 'left';
	}
}
