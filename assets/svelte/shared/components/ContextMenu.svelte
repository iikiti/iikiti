<script lang="ts">
	import Popover from './Popover.svelte';

	/**
	 * Generic dropdown / context menu. Knows nothing about blocks: callers pass
	 * a list of items and where to open it. Opened either at a pointer position
	 * (right-click) or anchored to an element (a button).
	 */
	export interface ContextMenuItem {
		id: string;
		label: string;
		/** Optional leading icon name, rendered by the caller's icon set. */
		icon?: string;
		disabled?: boolean;
		/** Renders the item in the destructive colour. */
		tone?: 'default' | 'destructive';
		onSelect: () => void;
	}

	let {
		items,
		x = null,
		y = null,
		anchor = null,
		placement = 'bottom',
		ariaLabel = 'Menu',
		onclose,
	}: {
		items: ContextMenuItem[];
		/** Viewport X for a pointer-positioned menu (ignored when `anchor` is set). */
		x?: number | null;
		/** Viewport Y for a pointer-positioned menu (ignored when `anchor` is set). */
		y?: number | null;
		/** Element to anchor to; takes precedence over x/y. */
		anchor?: HTMLElement | null;
		placement?: 'top' | 'bottom' | 'left' | 'right';
		ariaLabel?: string;
		onclose?: () => void;
	} = $props();

	/**
	 * Popover positions against an element, so a pointer position is mapped to a
	 * zero-size proxy element placed at the cursor.
	 */
	let proxy = $state<HTMLElement | null>(null);

	const resolvedAnchor = $derived.by(() => {
		if (anchor) return anchor;
		return proxy;
	});

	function select(item: ContextMenuItem) {
		if (item.disabled) return;
		item.onSelect();
		onclose?.();
	}

	function close() {
		onclose?.();
	}
</script>

{#if !anchor && x !== null && y !== null}
	<div
		bind:this={proxy}
		class="iikiti-menu-proxy"
		style:position="fixed"
		style:left="{x}px"
		style:top="{y}px"
		style:width="0px"
		style:height="0px"
		aria-hidden="true"
	></div>
{/if}

{#if resolvedAnchor}
	<Popover anchor={resolvedAnchor} {placement} closeOnOutside onclose={close} portal>
		<div class="iikiti-menu" role="menu" aria-label={ariaLabel}>
			{#each items as item (item.id)}
				<button
					type="button"
					role="menuitem"
					class="iikiti-menu__item"
					class:iikiti-menu__item--destructive={item.tone === 'destructive'}
					disabled={item.disabled}
					onclick={() => select(item)}
				>{item.label}</button>
			{/each}
		</div>
	</Popover>
{/if}

<style>
	/* The Popover container is portaled to body; lift it into the menu layer. */
	:global(.iikiti-popover:has(> .iikiti-menu)) {
		z-index: var(--iikiti-z-menu, 3950);
	}
	.iikiti-menu {
		min-width: 160px;
		padding: 4px;
		border: 1px solid var(--ik-panel-border, #ddd6cb);
		border-radius: 8px;
		background: var(--ik-panel-bg, #f9f7f2);
		box-shadow: 0 8px 24px rgba(0, 0, 0, 0.16);
		z-index: var(--iikiti-z-menu, 3950);
	}
	.iikiti-menu__item {
		display: block;
		width: 100%;
		padding: 6px 10px;
		border: 0;
		border-radius: 4px;
		background: transparent;
		color: var(--ik-panel-text, #3a3830);
		font-size: 0.875rem;
		text-align: left;
		cursor: pointer;
	}
	.iikiti-menu__item:hover:not(:disabled) {
		background: var(--ik-panel-hover, rgba(0, 0, 0, 0.05));
	}
	.iikiti-menu__item:disabled {
		opacity: 0.45;
		cursor: not-allowed;
	}
	.iikiti-menu__item--destructive {
		color: var(--ik-danger, #b3261e);
	}
</style>
