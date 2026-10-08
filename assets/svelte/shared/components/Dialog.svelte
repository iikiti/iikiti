<script module lang="ts">
	// Bring-to-front counter, shared by every Dialog in this bundle.
	let dialogSeq = 1;

	// Layer values mirror the `--iikiti-z-*` scale in assets/styles/ui.css.
	// The token is the source of truth; this is only the SSR/no-CSS fallback.
	const FLOATING_FALLBACK = 3000;

	function floatingBase(): number {
		if (typeof window === 'undefined') return FLOATING_FALLBACK;
		const raw = getComputedStyle(document.documentElement).getPropertyValue('--iikiti-z-floating');
		const v = parseInt(raw, 10);
		return Number.isFinite(v) && v > 0 ? v : FLOATING_FALLBACK;
	}
</script>

<script lang="ts">
	import { untrack } from 'svelte';
	import type { Snippet } from 'svelte';
	import Icon from './Icon.svelte';
	import { bindResize, readPersistedSize } from '$framework/chrome/dragResize.js';

	/**
	 * Base dialog built on the native `<dialog>` element.
	 *
	 * Non-modal (`modal=false`, the default) is a floating, draggable (and
	 * optionally resizable) dialog rendered via `dialog.show()` — "hover above
	 * everything" chrome in the floating z layer (`--iikiti-z-floating`).
	 *
	 * Modal (`modal=true`) calls `dialog.showModal()`, moving the dialog into
	 * the browser top layer with a `::backdrop` that covers the rest of the
	 * site. `ModalDialog.svelte` is a thin wrapper around this mode.
	 *
	 * Position/size persist to localStorage when `storageKey` is given
	 * (`iikiti.panel.<storageKey>.pos` / `.size`).
	 */
	interface Props {
		title: string;
		open?: boolean;
		onClose?: () => void;
		/** Render as a modal dialog (top layer + backdrop). */
		modal?: boolean;
		/** Initial position (px from the viewport top-left corner). */
		x?: number;
		y?: number;
		width?: number;
		height?: number;
		minWidth?: number;
		minHeight?: number;
		resizable?: boolean;
		closeable?: boolean;
		storageKey?: string;
		children: Snippet;
	}

	let {
		title,
		open = true,
		onClose,
		modal = false,
		x,
		y,
		width = 320,
		height = 420,
		minWidth = 220,
		minHeight = 140,
		resizable = true,
		closeable = true,
		storageKey = '',
		children,
	}: Props = $props();

	let panel: HTMLDialogElement | null = $state(null);
	let resizeHandle: HTMLDivElement | null = $state(null);
	// Capture the initial props once, outside the reactive graph — position/size
	// become local state from here on (persisted/restored by the dialog itself).
	const initial = untrack(() => ({ x, y, w: width, h: height }));
	let pos = $state({ x: initial.x ?? 0, y: initial.y ?? 60 });
	let size = $state({ w: initial.w, h: initial.h });
	let z = $state<number | undefined>(undefined);

	function bringToFront() {
		z = floatingBase() + dialogSeq++;
	}

	function storageKeyFor(kind: 'pos' | 'size') {
		return storageKey ? `iikiti.panel.${storageKey}.${kind}` : null;
	}

	/** Viewport clamp for arbitrary sizes (used by drag / window resize). */
	function clampXY(nextX: number, nextY: number, w: number, h: number) {
		const reachX = 48; // keep at least this much of the panel reachable
		const reachY = 40;
		const maxX = window.innerWidth - reachX;
		let minX = reachX - w;
		if (minX > maxX) minX = maxX;
		return {
			x: Math.min(Math.max(nextX, minX), maxX),
			y: Math.min(Math.max(nextY, 0), Math.max(0, window.innerHeight - reachY)),
		};
	}

	function clampToViewport(nextX: number, nextY: number) {
		return clampXY(nextX, nextY, size.w, size.h);
	}

	function startDrag(e: PointerEvent) {
		if (e.button !== 0 || !panel) return;
		const target = e.target as HTMLElement;
		if (target.closest('button, a, input, select, textarea, label, [data-iikiti-no-drag]')) return;
		e.preventDefault();
		bringToFront();
		const startX = e.clientX;
		const startY = e.clientY;
		const originX = pos.x;
		const originY = pos.y;
		const onMove = (ev: PointerEvent) => {
			pos = clampToViewport(originX + ev.clientX - startX, originY + ev.clientY - startY);
		};
		const onUp = () => {
			window.removeEventListener('pointermove', onMove);
			window.removeEventListener('pointerup', onUp);
			window.removeEventListener('pointercancel', onUp);
			const key = storageKeyFor('pos');
			if (key) {
				try {
					localStorage.setItem(key, JSON.stringify(pos));
				} catch {
					/* storage unavailable — position stays ephemeral */
				}
			}
		};
		window.addEventListener('pointermove', onMove);
		window.addEventListener('pointerup', onUp);
		window.addEventListener('pointercancel', onUp);
	}

	// Open/close the native dialog element. `showModal()` moves it to the top
	// layer (modal); `show()` keeps it in normal flow so `position: fixed` +
	// inline left/top control its placement.
	$effect(() => {
		const el = panel;
		if (!el) return;
		if (open) {
			if (modal) {
				if (!el.hasAttribute('data-iikiti-modal')) {
					el.setAttribute('data-iikiti-modal', 'true');
					el.showModal();
				}
			} else if (!el.open) {
				el.show();
			}
		} else if (el.open) {
			el.close();
		}
	});

	// Native `close` (Esc in modal mode, or programmatic `.close()`) → parent.
	$effect(() => {
		const el = panel;
		if (!el) return;
		const onNativeClose = () => onClose?.();
		el.addEventListener('close', onNativeClose);
		return () => el.removeEventListener('close', onNativeClose);
	});

	// Non-modal dialogs do not respond to Esc natively, so handle it here.
	$effect(() => {
		if (modal || !open) return;
		const onKey = (e: KeyboardEvent) => {
			if (e.key === 'Escape') onClose?.();
		};
		window.addEventListener('keydown', onKey);
		return () => window.removeEventListener('keydown', onKey);
	});

	// On open: restore persisted position/size (or place top-right), clamp into
	// the viewport and bring to front of any other dialog. Everything is computed
	// through locals — this effect must not read the reactive pos/size/z it writes,
	// or it would loop (effect_update_depth_exceeded).
	$effect(() => {
		if (!open || modal) return;
		const storedSize = storageKey ? readPersistedSize(`panel.${storageKey}.size`) : null;
		const nextSize = {
			w: storedSize && storedSize.w > 0 ? storedSize.w : initial.w,
			h: storedSize && storedSize.h > 0 ? storedSize.h : initial.h,
		};
		let nextX = initial.x ?? window.innerWidth - nextSize.w - 16;
		let nextY = initial.y ?? 64;
		const posKey = storageKeyFor('pos');
		if (posKey) {
			try {
				const raw = localStorage.getItem(posKey);
				if (raw) {
					const p = JSON.parse(raw);
					if (p && Number.isFinite(Number(p.x)) && Number.isFinite(Number(p.y))) {
						nextX = Number(p.x);
						nextY = Number(p.y);
					}
				}
			} catch {
				/* ignore corrupt storage */
			}
		}
		const clamped = clampXY(nextX, nextY, nextSize.w, nextSize.h);
		size = nextSize;
		pos = clamped;
		bringToFront();
	});

	// Keep the dialog in the viewport while the window resizes.
	$effect(() => {
		if (!open || modal) return;
		const onResize = () => {
			pos = clampToViewport(pos.x, pos.y);
		};
		window.addEventListener('resize', onResize);
		return () => window.removeEventListener('resize', onResize);
	});

	// Corner drag-resize via the shared pointer helper.
	$effect(() => {
		if (!open || modal || !resizable || !panel || !resizeHandle) return;
		const binding = bindResize(resizeHandle, panel, {
			edge: 'corner',
			minW: minWidth,
			minH: minHeight,
			maxW: Math.round(window.innerWidth * 0.95),
			maxH: Math.round(window.innerHeight * 0.85),
			persistKey: storageKey ? `panel.${storageKey}.size` : '',
			onStart: bringToFront,
			onEnd: (w, h) => {
				size = { w, h };
				pos = clampToViewport(pos.x, pos.y);
			},
		});
		return () => binding.destroy();
	});

	const panelStyle = $derived(
		modal
			? `margin:auto; width:${size.w}px; max-width:min(95vw, ${size.w}px);`
			: `position:fixed; left:${pos.x}px; top:${pos.y}px; width:${size.w}px; height:${size.h}px; margin:0; z-index:${z ?? floatingBase()};`,
	);

	// Drag wiring lives in an action so the trigger element carries no markup
	// event handlers (viewport-drag is pointer-only by design).
	function dragHeader(node: HTMLElement) {
		if (!modal) node.addEventListener('pointerdown', startDrag);
		return {
			destroy() {
				node.removeEventListener('pointerdown', startDrag);
			},
		};
	}
</script>

<dialog
	bind:this={panel}
	class="iikiti-dialog"
	class:modal={modal}
	aria-modal={modal ? 'true' : 'false'}
	aria-label={title}
	style={panelStyle}
>
	<header class="iikiti-dialog__header" use:dragHeader>
		<span class="iikiti-dialog__title">{title}</span>
		{#if closeable}
			<button
				type="button"
				class="iikiti-dialog__close"
				aria-label="Close {title}"
				onclick={() => onClose?.()}
			>
				<Icon name="x" size={16} />
			</button>
		{/if}
	</header>
	<div class="iikiti-dialog__body">
		{@render children()}
	</div>
	{#if resizable && !modal}
		<div
			bind:this={resizeHandle}
			class="iikiti-resize-handle iikiti-dialog__resize"
			data-edge="corner"
			aria-hidden="true"
		></div>
	{/if}
</dialog>

<style>
	.iikiti-dialog {
		display: flex;
		flex-direction: column;
		inset: auto;
		padding: 0;
		max-width: 95vw;
		max-height: 85vh;
		background: var(--ik-panel-bg, #f9f7f2);
		color: var(--ik-panel-text, #3a3830);
		border: 1px solid var(--ik-panel-border, #ddd6cb);
		border-radius: var(--ik-radius, 0.75rem);
		box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
	}

	.iikiti-dialog:modal {
		position: fixed;
		inset: 0;
	}

	.iikiti-dialog::backdrop {
		background: rgba(15, 15, 12, 0.45);
	}

	.iikiti-dialog__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		padding: 6px 6px 6px 12px;
		border-bottom: 1px solid var(--ik-panel-border, #ddd6cb);
		cursor: grab;
		touch-action: none;
		user-select: none;
		-webkit-user-select: none;
	}

	.iikiti-dialog:modal .iikiti-dialog__header {
		cursor: default;
	}

	.iikiti-dialog__header:active {
		cursor: grabbing;
	}

	.iikiti-dialog__title {
		font-size: 0.8125rem;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.iikiti-dialog__close {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 26px;
		height: 26px;
		border: none;
		border-radius: 6px;
		background: transparent;
		color: var(--ik-panel-text-muted, #7a7669);
		cursor: pointer;
		flex-shrink: 0;
	}

	.iikiti-dialog__close:hover {
		background: color-mix(in srgb, var(--ik-panel-text, #3a3830) 12%, transparent);
		color: var(--ik-panel-text, #3a3830);
	}

	.iikiti-dialog__body {
		flex: 1;
		overflow: auto;
		padding: 8px;
		min-height: 60px;
	}
</style>
