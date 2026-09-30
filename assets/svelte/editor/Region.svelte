<script lang="ts">
	import { tree, selected, select, addBlock } from './state';
	import type { BlockNode } from './state';
	import BlockView from './BlockView.svelte';
	import BlockPalette from './BlockPalette.svelte';

	let { regionId }: { regionId: string } = $props();

	const nodes = $derived(($tree[regionId] ?? []) as BlockNode[]);

	let paletteAnchor: HTMLElement | null = $state(null);
</script>

<div class="iikiti-region-content" data-region-content={regionId}>
	{#each nodes as node (node.id)}
		<BlockView {node} {regionId} />
	{/each}
	{#if nodes.length === 0}<em class="iikiti-block--placeholder">Empty region — click “Add block” to start</em>{/if}
</div>

<div class="iikiti-region-controls" data-region-controls={regionId}>
	<button
		class="iikiti-btn iikiti-btn--add"
		title="Add block to {regionId}"
		aria-label="Add block to {regionId}"
		onclick={(e) => { e.stopPropagation(); e.preventDefault(); paletteAnchor = e.currentTarget as HTMLElement; }}
	>
		<span class="iikiti-btn__icon">+</span>
	</button>
	{#if paletteAnchor}
		<BlockPalette
			allowedTypes={[]}
			anchor={paletteAnchor}
			onClose={() => (paletteAnchor = null)}
			onSelect={(type) => { paletteAnchor = null; addBlock(regionId, null, type); }}
		/>
	{/if}
</div>

<style>
	.iikiti-region-content {
		min-height: 2rem;
		position: relative;
	}
	:global(.iikiti-region-content:hover .iikiti-region-controls) {
		opacity: 1;
	}
	:global(.iikiti-touch .iikiti-region-controls) {
		opacity: 1;
	}
	.iikiti-region-controls {
		position: absolute;
		top: 4px;
		right: 4px;
		opacity: 0;
		transition: opacity 0.15s ease;
		pointer-events: auto;
	}
	.iikiti-region-controls .iikiti-btn--add {
		width: 24px;
		height: 24px;
		padding: 0;
		min-width: 24px;
		font-size: 14px;
		border-radius: 50%;
	}
</style>
