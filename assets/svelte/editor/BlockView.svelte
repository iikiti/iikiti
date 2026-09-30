<script lang="ts">
	import { onDestroy } from 'svelte';
	import { get } from 'svelte/store';
	import { selected, updateNode, registerBlock, deleteBlock, moveBlock, addBlock, blockTypes, tree } from './state';
	import type { BlockNode } from './state';
	import Popover from '$components/Popover.svelte';
	import BlockPalette from './BlockPalette.svelte';

	let { node, regionId }: { node: BlockNode; regionId: string } = $props();
	let self: HTMLDivElement;

	const isSelected = $derived($selected === node.id);

	$effect(() => {
		if (self) {
			self.dataset.blockId = node.id;
			self.dataset.blockType = node.type;
			registerBlock(node.id, self);
		}
	});

	onDestroy(() => registerBlock(node.id, null));

	function pick(ev: MouseEvent) {
		ev.stopPropagation();
		selected.set(node.id);
	}

	let menuAnchor: HTMLElement | null = $state(null);
	let paletteAnchor: HTMLElement | null = $state(null);

	const isContainer = $derived(node.type === 'container');
	const childTypes = $derived.by(() => {
		const bt = $blockTypes;
		const schema = bt?.[node.type];
		if (!schema) return [];
		if (schema.allowedChildTypes === null || schema.allowedChildTypes === undefined) {
			return schema.acceptsChildren ? Object.keys(bt ?? {}) : [];
		}
		return (schema.allowedChildTypes as string[]) ?? [];
	});

	function deleteNode(ev: MouseEvent) {
		ev.stopPropagation();
		ev.preventDefault();
		deleteBlock(node.id);
		menuAnchor = null;
		selected.set(null);
	}

	function moveUp(ev: MouseEvent) {
		ev.stopPropagation();
		ev.preventDefault();
		moveBlockUp(regionId, node);
		menuAnchor = null;
	}

	function moveDown(ev: MouseEvent) {
		ev.stopPropagation();
		ev.preventDefault();
		moveBlockDown(regionId, node);
		menuAnchor = null;
	}

	function moveBlockUp(region: string, n: BlockNode) {
		const nodes = (get(tree)[region] ?? []);
		const idx = nodes.findIndex((x) => x.id === n.id);
		if (idx > 0) {
			moveBlock(n.id, null, idx - 1);
		}
	}

	function moveBlockDown(region: string, n: BlockNode) {
		const nodes = (get(tree)[region] ?? []);
		const idx = nodes.findIndex((x) => x.id === n.id);
		if (idx >= 0 && idx < nodes.length - 1) {
			moveBlock(n.id, null, idx + 1);
		}
	}

	function openAddChild(ev: MouseEvent) {
		ev.stopPropagation();
		ev.preventDefault();
		paletteAnchor = ev.currentTarget as HTMLElement;
		menuAnchor = null;
	}

	function insertChild(type: string) {
		addBlock(regionId, node.id, type);
		paletteAnchor = null;
	}
</script>

<div
	bind:this={self}
	class:selected={isSelected}
	class="iikiti-block-preview"
	data-block-node
	tabindex="0"
	role="button"
	aria-label="Select block"
	onclick={pick}
	onkeydown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(e); } }}
>
	{#if node.type === 'text'}
		{@html node.content?.content ?? ''}
	{:else if node.type === 'heading'}
		<svelte:element this={'h' + Number(node.content?.level ?? 2)}>{node.content?.text ?? ''}</svelte:element>
	{:else if node.type === 'image'}
		{#if node.content?.source?.url}
			<img src={node.content.source.url} alt={node.content.alt ?? ''} class="iikiti-image" />
		{:else}<em class="iikiti-block--placeholder">Image URL missing</em> {/if}
	{:else if node.type === 'container'}
		<div class="iikiti-container" data-block-children>
			{#each node.children ?? [] as child (child.id)}<BlockView node={child} {regionId} />{/each}
		</div>
	{:else if node.type === 'video_embed'}
		<iframe src={node.content?.url} title="Embedded content" class="iikiti-embed__iframe" allowfullscreen loading="lazy"></iframe>
	{:else if node.type === 'social_embed'}
		{#if node.content?.url}<a href={node.content.url} class="iikiti-embed--link-card">{node.content.url}</a>{/if}
	{:else if node.type === 'query'}
		<em class="iikiti-block--placeholder">Query block (preview via API)</em>
	{:else if node.type === 'dynamic'}
		<em class="iikti-block--placeholder">Dynamic content region</em>
	{:else}
		<em class="iikiti-block--placeholder">Unknown block type</em>
	{/if}
	<div class="iikiti-outline iikiti-outline--selected" aria-hidden="true"></div>
	<div class="iikiti-context-menu">
		<button class="iikiti-btn iikiti-btn--sm" title="Select block" onclick={() => { selected.set(node.id); }}>✏</button>
		<button class="iikiti-btn iikiti-btn--sm" title="More actions" onclick={(e) => { e.stopPropagation(); e.preventDefault(); menuAnchor = e.currentTarget as HTMLElement; }}>⋮</button>
	</div>
	{#if menuAnchor}
		<Popover anchor={menuAnchor} placement="bottom-end" closeOnOutside onclose={() => (menuAnchor = null)}>
			<div class="iikiti-block-menu">
				{#if isContainer}
					<button class="iikiti-block-menu__item" onclick={openAddChild}>Add child…</button>
				{/if}
				<button class="iikiti-block-menu__item" onclick={moveUp}>Move up</button>
				<button class="iikiti-block-menu__item" onclick={moveDown}>Move down</button>
				<button class="iikiti-block-menu__item iikiti-block-menu__item--destructive" onclick={deleteNode}>Delete</button>
			</div>
		</Popover>
	{/if}
	{#if paletteAnchor}
		<BlockPalette
			allowedTypes={childTypes}
			anchor={paletteAnchor}
			onClose={() => (paletteAnchor = null)}
			onSelect={insertChild}
		/>
	{/if}
</div>

<style>
	.iikiti-block-preview[data-block-node] { position: relative; }
	.iikiti-block-preview.selected { outline: 2px solid #3b82f6; outline-offset: 2px; }
	.iikiti-block-preview:hover { outline: 1px dashed #93c5fd; outline-offset: 1px; }
	.iikiti-block-preview:active { outline: 2px solid #60a5fa; outline-offset: 2px; }
	.iikiti-outline { position: absolute; inset: 0; border-radius: 3px; pointer-events: none; }
	.iikiti-outline--selected { border: 2px dashed #3b82f6; }
	.iikiti-context-menu {
		position: absolute;
		top: 2px;
		right: 2px;
		display: flex;
		gap: 2px;
		opacity: 0;
		transition: opacity 0.15s ease;
		pointer-events: auto;
		z-index: 2;
	}
	.iikiti-block-preview:hover .iikiti-context-menu,
	.iikiti-block-preview.selected .iikiti-context-menu {
		opacity: 1;
	}
	:global(.iikiti-touch) .iikiti-context-menu {
		opacity: 1;
	}
	.iikiti-btn--sm {
		width: 20px;
		height: 20px;
		padding: 0 2px;
		min-width: 20px;
		font-size: 11px;
		border-radius: 3px;
	}
	.iikiti-block-menu {
		display: flex;
		flex-direction: column;
		min-width: 140px;
		padding: 4px;
		background: #ffffff;
		border: 1px solid #d1d5db;
		border-radius: 4px;
		box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
	}
	.iikiti-block-menu__item {
		padding: 4px 8px;
		font-size: 13px;
		text-align: left;
		border: none;
		background: transparent;
		color: #111827;
		cursor: pointer;
		border-radius: 3px;
	}
	.iikiti-block-menu__item:hover { background: #f3f4f6; }
	.iikiti-block-menu__item--destructive { color: #dc2626; }
	.iikiti-block-menu__item--destructive:hover { background: #fef2f2; }
	@media (prefers-color-scheme: dark) {
		.iikiti-block-menu { background: #1f2937; border-color: #374151; color: #f3f4f6; }
		.iikiti-block-menu__item:hover { background: #374151; }
		.iikiti-block-menu__item--destructive:hover { background: #7f1d1d; }
	}
</style>
