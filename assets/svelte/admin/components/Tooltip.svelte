<script lang="ts">
	import { onMount, tick } from 'svelte';
	import type { Snippet } from 'svelte';

	interface Props {
		/** Tooltip text (plain text only — not HTML). */
		content: string;
		/** Preferred side relative to the mouse cursor (flips at viewport edges). */
		placement?: 'top' | 'bottom' | 'left' | 'right';
		/** Show delay in ms (hover/focus must persist this long). */
		delay?: number;
		disabled?: boolean;
		/**
		 * Stacking order of the tip. Defaults to the shared tooltip layer token;
		 * pass a number (or any CSS z-index value) to override per instance.
		 */
		zIndex?: number | string;
		/** The trigger element to wrap. */
		children: Snippet;
	}

	let {
		content,
		placement = 'bottom',
		delay = 350,
		disabled = false,
		zIndex = 'var(--iikiti-z-tooltip)',
		children,
	}: Props = $props();

	let trigger: HTMLElement | null = $state(null);
	let tip: HTMLElement | null = $state(null);
	let visible = $state(false);
	// Svelte 5 dropped object support for the `style` attribute — position via
	// `style:` directives (see the template).
	let style = $state<{ top?: string; left?: string }>({});
	let timer: ReturnType<typeof setTimeout> | null = null;
	/** Latest pointer position inside the trigger (null for keyboard focus). */
	let pointer = $state<{ x: number; y: number } | null>(null);

	const OFFSET = 12;
	const MARGIN = 8;

	/** Move the node to document.body so it escapes any ancestor stacking context. */
	function portal(node: HTMLElement) {
		document.body.appendChild(node);
		return {
			destroy() {
				node.remove();
			},
		};
	}

	function position() {
		if (!trigger) return;
		const rect = trigger.getBoundingClientRect();
		const box = tip?.getBoundingClientRect() ?? { width: 0, height: 0 };
		// Anchor on the mouse cursor when hovering; fall back to the trigger
		// box for keyboard focus so the tip still sits sensibly.
		const baseX = pointer?.x ?? rect.left;
		const baseY = pointer?.y ?? rect.bottom;
		let top = 0;
		let left = 0;

		switch (placement) {
			case 'top':
				// Above the cursor, always clear of the hovered element.
				top = Math.min(baseY - OFFSET - box.height, rect.top - OFFSET - box.height);
				left = baseX + OFFSET;
				break;
			case 'left':
				top = baseY - box.height / 2;
				left = Math.min(baseX - OFFSET - box.width, rect.left - OFFSET - box.width);
				break;
			case 'right':
				top = baseY - box.height / 2;
				left = Math.max(baseX + OFFSET, rect.right + OFFSET);
				break;
			default:
				// Below the cursor, never over the hovered element.
				top = Math.max(baseY + OFFSET, rect.bottom + OFFSET);
				left = baseX + OFFSET;
		}

		// Flip to the opposite side when the preferred one has no room.
		if (placement === 'bottom' && top + box.height > window.innerHeight - MARGIN) {
			top = Math.min(baseY - OFFSET - box.height, rect.top - OFFSET - box.height);
		} else if (placement === 'top' && top < MARGIN) {
			top = Math.max(baseY + OFFSET, rect.bottom + OFFSET);
		}
		if (
			(placement === 'bottom' || placement === 'top') &&
			left + box.width > window.innerWidth - MARGIN
		) {
			// Mirror to the cursor's left rather than hanging off-screen.
			left = baseX - OFFSET - box.width;
		} else if (placement === 'right' && left + box.width > window.innerWidth - MARGIN) {
			left = baseX - OFFSET - box.width;
		} else if (placement === 'left' && left < MARGIN) {
			left = baseX + OFFSET;
		}

		left = Math.min(Math.max(MARGIN, left), Math.max(MARGIN, window.innerWidth - box.width - MARGIN));
		top = Math.min(Math.max(MARGIN, top), Math.max(MARGIN, window.innerHeight - box.height - MARGIN));

		style = { top: `${Math.round(top)}px`, left: `${Math.round(left)}px` };
	}

	async function show() {
		if (disabled || !content) return;
		visible = true;
		await tick();
		position();
	}

	function enter() {
		if (disabled) return;
		if (timer) clearTimeout(timer);
		timer = setTimeout(() => {
			timer = null;
			void show();
		}, delay);
	}

	function hide() {
		if (timer) {
			clearTimeout(timer);
			timer = null;
		}
		visible = false;
	}

	function within(node: EventTarget | null): boolean {
		return !!trigger && !!node && (node === trigger || trigger.contains(node as Node));
	}

	// Hover/focus tracking via capture-phase window listeners (the same
	// pattern as Popover.svelte's outside-click): markup stays free of
	// handlers, and focus/blur still drives the tooltip (keyboard a11y).
	// The pointer position is tracked so the tip anchors below the cursor
	// rather than on top of the hovered element.
	onMount(() => {
		const onOver = (e: PointerEvent) => {
			if (!within(e.target) || within(e.relatedTarget)) return;
			pointer = { x: e.clientX, y: e.clientY };
			enter();
		};
		const onOut = (e: PointerEvent) => {
			if (!within(e.target) || within(e.relatedTarget)) return;
			pointer = null;
			hide();
		};
		const onMove = (e: PointerEvent) => {
			if (within(e.target)) pointer = { x: e.clientX, y: e.clientY };
		};
		const onFocusIn = (e: FocusEvent) => {
			if (within(e.target)) enter();
		};
		const onFocusOut = (e: FocusEvent) => {
			if (within(e.target)) hide();
		};
		window.addEventListener('pointerover', onOver, true);
		window.addEventListener('pointerout', onOut, true);
		window.addEventListener('pointermove', onMove, true);
		window.addEventListener('focusin', onFocusIn, true);
		window.addEventListener('focusout', onFocusOut, true);
		return () => {
			window.removeEventListener('pointerover', onOver, true);
			window.removeEventListener('pointerout', onOut, true);
			window.removeEventListener('pointermove', onMove, true);
			window.removeEventListener('focusin', onFocusIn, true);
			window.removeEventListener('focusout', onFocusOut, true);
			hide();
		};
	});

	// Keep the fixed-positioned tip anchored while its trigger moves
	// (sticky bars, scroll containers, window resizes).
	$effect(() => {
		if (!visible) return;
		const onMove = () => position();
		window.addEventListener('scroll', onMove, { passive: true, capture: true });
		window.addEventListener('resize', onMove, { passive: true });
		return () => {
			window.removeEventListener('scroll', onMove, { capture: true });
			window.removeEventListener('resize', onMove);
		};
	});
</script>

<span bind:this={trigger} class="iikiti-tooltip-trigger">
	{@render children()}
</span>
{#if visible}
	<!-- Rendered through a body portal so no ancestor stacking context
	     (viewport bars, sticky rails) can trap it beneath other chrome. -->
	<div
		use:portal
		bind:this={tip}
		class="iikiti-tooltip"
		role="tooltip"
		style:top={style.top}
		style:left={style.left}
		style:z-index={String(zIndex)}
	>{content}</div>
{/if}

<style>
	.iikiti-tooltip-trigger {
		display: inline-flex;
		max-width: 100%;
	}
</style>
