<script module lang="ts">
	// Bring-to-front counter, shared by every FloatingPanel in this bundle.
	let panelSeq = 1;

	function floatingBase(): number {
		if (typeof window === 'undefined') return 3000;
		const raw = getComputedStyle(document.documentElement).getPropertyValue('--iikiti-z-floating');
		const v = parseInt(raw, 10);
		return Number.isFinite(v) && v > 0 ? v : 3000;
	}
</script>

<script lang="ts">
	import { untrack } from 'svelte';
	import type { Snippet } from 'svelte';
	import Icon from './Icon.svelte';
	import { bindResize, readPersistedSize } from '$framework/chrome/dragResize.js';

	/**
	 * Generic floating, draggable (and optionally resizable) dialog —
	 * "hover above everything" chrome rendered in the floating z layer
	 * (`--iikiti-z-floating`, ui.css). Panels bring themselves to front on
	 * pointer interaction. Position/size persist to localStorage when
	 * `storageKey` is given (`iikiti.panel.<storageKey>.pos` /
	 * `.size`).
	 *
	 * Used by the editor's Layers navigator and exported via
	 * `@iikiti/admin` so plugins can build their own floating tools.
	 */
	interface Props {
		title: string;
		open?: boolean;
		onClose?: () => void;
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

	let panel: HTMLDivElement | null = $state(null);
	let resizeHandle: HTMLDivElement | null = $state(null);
	// Capture the initial props once, outside the reactive graph —
	// position/size become local state from here on (persisted/restored
	// by the panel itself).
	const initial = untrack(() => ({ x, y, w: width, h: height }));
	let pos = $state({ x: initial.x ?? 0, y: initial.y ?? 60 });
	let size = $state({ w: initial.w, h: initial.h });
	let z = $state<number | undefined>(undefined);

	function bringToFront() {
		z = floatingBase() + panelSeq++;
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

	// On open: restore persisted position/size (or place top-right), clamp
	// into the viewport and bring to front of any other panel. Everything is
	// computed through locals — this effect must not read the reactive
	// pos/size/z it writes, or it would loop (effect_update_depth_exceeded).
	$effect(() => {
		if (!open) return;
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

	// Keep the panel in the viewport while the window resizes.
	$effect(() => {
		if (!open) return;
		const onResize = () => {
			pos = clampToViewport(pos.x, pos.y);
		};
		window.addEventListener('resize', onResize);
		return () => window.removeEventListener('resize', onResize);
	});

	// Corner drag-resize via the shared pointer helper.
	$effect(() => {
		if (!open || !resizable || !panel || !resizeHandle) return;
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

	// Esc closes the panel (modeless dialog, so only while it is open).
	$effect(() => {
		if (!open) return;
		const onKey = (e: KeyboardEvent) => {
			if (e.key === 'Escape') onClose?.();
		};
		window.addEventListener('keydown', onKey);
		return () => window.removeEventListener('keydown', onKey);
	});

	// Drag wiring lives in an action so the trigger element carries no
	// markup event handlers (viewport-drag is pointer-only by design; the
	// panel itself is a role="dialog" and closes via Esc).
	function dragHeader(node: HTMLElement) {
		node.addEventListener('pointerdown', startDrag);
		return {
			destroy() {
				node.removeEventListener('pointerdown', startDrag);
			},
		};
	}
</script>

{#if open}
	<div
		bind:this={panel}
		class="iikiti-floating-panel"
		role="dialog"
		aria-modal="false"
		aria-label={title}
		style="left: {pos.x}px; top: {pos.y}px; width: {size.w}px; height: {size.h}px; z-index: {z};"
	>
		<header class="iikiti-floating-panel__header" use:dragHeader>
			<span class="iikiti-floating-panel__title">{title}</span>
			{#if closeable}
				<button
					type="button"
					class="iikiti-floating-panel__close"
					aria-label="Close {title} panel"
					onclick={() => onClose?.()}
				>
					<Icon name="x" size={16} />
				</button>
			{/if}
		</header>
		<div class="iikiti-floating-panel__body">
			{@render children()}
		</div>
		{#if resizable}
			<div
				bind:this={resizeHandle}
				class="iikiti-resize-handle iikiti-floating-panel__resize"
				data-edge="corner"
				aria-hidden="true"
			></div>
		{/if}
	</div>
{/if}

<style>
	.iikiti-floating-panel {
		display: flex;
		flex-direction: column;
		max-width: 95vw;
		max-height: 85vh;
		background: var(--ik-panel-bg, #f9f7f2);
		color: var(--ik-panel-text, #3a3830);
		border: 1px solid var(--ik-panel-border, #ddd6cb);
		border-radius: var(--ik-radius, 0.75rem);
		box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
	}

	.iikiti-floating-panel__header {
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

	.iikiti-floating-panel__header:active {
		cursor: grabbing;
	}

	.iikiti-floating-panel__title {
		font-size: 0.8125rem;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.iikiti-floating-panel__close {
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

	.iikiti-floating-panel__close:hover {
		background: color-mix(in srgb, var(--ik-panel-text, #3a3830) 12%, transparent);
		color: var(--ik-panel-text, #3a3830);
	}

	.iikiti-floating-panel__body {
		flex: 1;
		overflow: auto;
		padding: 8px;
		min-height: 60px;
	}
</style>
