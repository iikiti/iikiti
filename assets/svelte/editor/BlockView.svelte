<script lang="ts">
	import { onDestroy } from 'svelte';
	import { get } from 'svelte/store';
	import {
		registerBlock,
		select,
		selected,
		openAddBlockDialog,
		allowedChildTypes,
		blockTypes,
		searchNode,
	} from './state';
	import type { BlockNode } from './state';
	import BlockContent from './BlockContent.svelte';

	let {
		node,
		regionId,
		readonly = false,
		parentId = null,
		index = 0,
		isFirst = false,
		isLast = false,
		compact = false,
	}: {
		node: BlockNode;
		regionId: string;
		readonly?: boolean;
		parentId?: string | null;
		index?: number;
		isFirst?: boolean;
		isLast?: boolean;
		compact?: boolean;
	} = $props();

	let blockElement = $state<HTMLDivElement | null>(null);
	let hoverActive = $state(false);
	let hoveredDescendant = $state(false);
	let hoverTimer: ReturnType<typeof setTimeout> | null = null;
	let lastPointerX = 0;
	let lastPointerY = 0;
	const isSelected = $derived(!readonly && $selected === node.id);

	function clearHoverTimer() {
		if (hoverTimer === null) return;
		clearTimeout(hoverTimer);
		hoverTimer = null;
	}

	function scheduleHoverActivation() {
		if (hoverActive || hoverTimer !== null) return;
		hoverTimer = setTimeout(() => {
			hoverTimer = null;
			const deepestBlock = blockElement?.ownerDocument.elementFromPoint(lastPointerX, lastPointerY)?.closest('[data-block-node]');
			if (blockElement?.matches(':hover') && deepestBlock === blockElement) hoverActive = true;
		}, 140);
	}

	function updatePointerTarget(event: PointerEvent) {
		lastPointerX = event.clientX;
		lastPointerY = event.clientY;
		const target = event.target;
		if (!(target instanceof Element)) return;

		const activeBlock = target.closest<HTMLElement>('[data-block-node]');
		if (!activeBlock) return;

		let ancestor: HTMLElement | null = activeBlock;
		while (ancestor) {
			ancestor.dispatchEvent(new CustomEvent('iikiti-pointer-target', {
				bubbles: false,
				detail: { activeBlock },
			}));
			ancestor = ancestor.parentElement?.closest<HTMLElement>('[data-block-node]') ?? null;
		}
	}


	function handlePointerTarget(event: Event) {
		const activeBlock = (event as CustomEvent<{ activeBlock: Element }>).detail.activeBlock;
		const isActiveBlock = activeBlock === blockElement;
		hoveredDescendant = !isActiveBlock;
		if (!isActiveBlock) {
			hoverActive = false;
			clearHoverTimer();
			return;
		}
		scheduleHoverActivation();
	}

	function handlePointerMove(event: PointerEvent) {
		updatePointerTarget(event);
	}

	function selectBlock(event: MouseEvent) {
		event.stopPropagation();
		select(node.id);
	}

	function allowedTypesAtParent(): string[] {
		if (!parentId) return ['container'];
		const parent = searchNode(parentId);
		return parent ? allowedChildTypes(parent.type, get(blockTypes)) : [];
	}

	function insertAt(position: number, event: MouseEvent) {
		event.stopPropagation();
		event.preventDefault();
		openAddBlockDialog({ regionId, parentId, position, allowedTypes: allowedTypesAtParent() });
	}

	$effect(() => {
		if (!blockElement || readonly) return;
		blockElement.dataset.blockId = node.id;
		blockElement.dataset.blockType = node.type;
		registerBlock(node.id, blockElement);
	});

	$effect(() => {
		if (!blockElement || readonly) return;
		blockElement.addEventListener('iikiti-pointer-target', handlePointerTarget);
		return () => blockElement?.removeEventListener('iikiti-pointer-target', handlePointerTarget);
	});

	onDestroy(() => {
		clearHoverTimer();
		if (!readonly) registerBlock(node.id, null);
	});
</script>

{#if readonly}
	<div bind:this={blockElement} class="iikiti-block-preview iikiti-block-preview--readonly" data-block-node data-block-readonly>
		<BlockContent {node} {regionId} {readonly} parentId={node.id} />
	</div>
{:else}
	<div
		bind:this={blockElement}
		class="iikiti-block-preview"
		class:selected={isSelected}
		class:compact
		class:iikiti-block-preview--first={isFirst}
		class:iikiti-block-preview--last={isLast}
		class:iikiti-block-preview--hover-active={hoverActive}
		class:iikiti-block-preview--hover-ancestor={hoveredDescendant}
		data-block-node
		tabindex="0"
		role="button"
		aria-label="Select block"
		onpointermove={handlePointerMove}
		onclick={selectBlock}
		onkeydown={(event) => {
			if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault();
				selectBlock(event);
			}
		}}
	>
		<BlockContent {node} {regionId} {parentId} {readonly} />
		<button type="button" class="iikiti-insert-btn iikiti-insert-btn--before" title="Insert block before" aria-label="Insert block before" onclick={(event) => insertAt(index, event)}>
			<span aria-hidden="true">+</span>
		</button>
		<button type="button" class="iikiti-insert-btn iikiti-insert-btn--after" title="Insert block after" aria-label="Insert block after" onclick={(event) => insertAt(index + 1, event)}>
			<span aria-hidden="true">+</span>
		</button>
	</div>
{/if}

<style>
	.iikiti-block-preview[data-block-node] { position: relative; }
	.iikiti-block-preview--readonly { pointer-events: none; }
	.iikiti-block-preview:not(.iikiti-block-preview--readonly) {
		margin-block: 0;
		padding-block: 0;
		transition: outline-color 0.15s ease;
	}
	.iikiti-block-preview--hover-ancestor {
		position: relative;
		z-index: 1;
		padding-block: 12px;
		outline: 1px dashed #9ca3af;
		outline-offset: 4px;
	}
	.iikiti-block-preview--hover-active {
		margin-block: 12px;
		padding-block: 24px;
		outline: 1px dashed #93c5fd;
		outline-offset: 1px;
	}
	.iikiti-block-preview--first.iikiti-block-preview--hover-active,
	.iikiti-block-preview--first.iikiti-block-preview--hover-ancestor { padding-top: 48px; }
	.iikiti-block-preview--last.iikiti-block-preview--hover-active,
	.iikiti-block-preview--last.iikiti-block-preview--hover-ancestor { padding-bottom: 48px; }
	.iikiti-block-preview--hover-active { outline: 1px dashed #93c5fd; outline-offset: 1px; }
	@media (pointer: coarse), (hover: none) {
		.iikiti-block-preview.selected {
			margin-block: 12px;
			padding-block: 24px;
		}
		.iikiti-block-preview--first.selected { padding-top: 48px; }
		.iikiti-block-preview--last.selected { padding-bottom: 48px; }
	}
	@media (prefers-reduced-motion: reduce) {
		.iikiti-block-preview:not(.iikiti-block-preview--readonly) { transition: none; }
	}
	.iikiti-block-preview::before,
	.iikiti-block-preview::after {
		position: absolute;
		left: 0;
		z-index: var(--iikiti-z-editor-controls);
		width: 100%;
		height: 56px;
		content: '';
		pointer-events: none;
	}
	.iikiti-block-preview::before { bottom: 100%; }
	.iikiti-block-preview::after { top: 100%; }
	.iikiti-block-preview--first::before,
	.iikiti-block-preview--last::after { height: 80px; }
	.iikiti-block-preview--hover-active::before,
	.iikiti-block-preview--hover-active::after { pointer-events: auto; }
	.iikiti-insert-btn {
		position: absolute;
		top: 0;
		left: 50%;
		z-index: var(--iikiti-z-editor-controls);
		display: flex;
		align-items: center;
		justify-content: center;
		width: 32px;
		height: 32px;
		padding: 0;
		border: 0;
		border-radius: 50%;
		background: var(--ik-panel-bg, #ffffff);
		color: var(--ik-accent, #a6613c);
		font-size: 1rem;
		line-height: 1;
		cursor: pointer;
		visibility: hidden;
		opacity: 0;
		pointer-events: none;
		transition: transform 0.18s ease, opacity 0.12s ease, visibility 0s linear 0.12s;
		transform: translate(-50%, -100%);
	}
	.iikiti-insert-btn::before {
		position: absolute;
		inset: 0;
		border: 1px solid var(--ik-panel-border, #d1d5db);
		border-radius: 50%;
		background: var(--ik-panel-bg, #ffffff);
		box-shadow: 0 1px 4px rgba(0, 0, 0, 0.18);
		content: '';
	}
	.iikiti-insert-btn > span { position: relative; z-index: 1; font-size: 20px; line-height: 1; }
	.iikiti-insert-btn--before { transform: translate(-50%, -100%); }
	.iikiti-insert-btn--after { top: auto; bottom: 0; transform: translate(-50%, 100%); }
	.iikiti-block-preview--first > .iikiti-insert-btn--before { top: 32px; }
	.iikiti-block-preview--last > .iikiti-insert-btn--after { bottom: 32px; }
	.iikiti-block-preview--hover-active > .iikiti-insert-btn,
	.iikiti-insert-btn:focus-visible { visibility: visible; opacity: 1; pointer-events: auto; transition-delay: 0s; }
	.iikiti-insert-btn:hover::before,
	.iikiti-insert-btn:focus-visible::before { border-color: color-mix(in srgb, var(--ik-accent, #a6613c) 70%, var(--ik-panel-border, #d1d5db)); }
</style>
