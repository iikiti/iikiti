<script lang="ts">
	import { tree, regions, openAddBlockDialog, regionAllowedTypes } from './state';
	import type { BlockNode } from './state';
	import BlockView from './BlockView.svelte';

	let {
		regionId,
		editable = true,
		onActivate,
		name = '',
	}: {
		regionId: string;
		editable?: boolean;
		onActivate?: (id: string) => void;
		name?: string;
	} = $props();

	const nodes = $derived(($tree[regionId] ?? []) as BlockNode[]);
	/** Region-level insertable types (empty = any non-inline type). */
	const regionAllowed = $derived(regionAllowedTypes(regionId, $regions));

	function activate() {
		onActivate?.(regionId);
	}
</script>

{#if editable}
	<div class="iikiti-region-content" data-region-content={regionId}>
		{#each nodes as node, i (node.id)}
			<BlockView {node} {regionId} parentId={null} index={i} />
		{/each}
		{#if nodes.length === 0}<em class="iikiti-block--placeholder">Empty region — click “Add block” to start</em>{/if}
	</div>

	<div class="iikiti-region-controls" data-region-controls={regionId}>
		<button
			class="iikiti-btn iikiti-btn--add"
			title="Add block to {regionId}"
			aria-label="Add block to {regionId}"
			onclick={(e) => {
				e.stopPropagation();
				e.preventDefault();
				openAddBlockDialog({ regionId, parentId: null, allowedTypes: regionAllowed });
			}}
		>
			<span class="iikiti-btn__icon">+</span>
		</button>
	</div>
{:else}
	<div
		class="iikiti-region-locked"
		data-region-locked={regionId}
		data-tour="region.{regionId}"
		role="button"
		tabindex="0"
		aria-label="Edit {name || regionId} template"
		onclick={activate}
		onkeydown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(); } }}
	>
		<span class="iikiti-region-locked__badge">Edit {name || regionId} template</span>
		<div class="iikiti-region-content" data-region-content={regionId}>
			{#each nodes as node (node.id)}
				<BlockView {node} {regionId} readonly />
			{/each}
			{#if nodes.length === 0}<em class="iikiti-block--placeholder">Empty {name || regionId}</em>{/if}
		</div>
	</div>
{/if}

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
	.iikiti-region-locked {
		position: relative;
		cursor: pointer;
		border-radius: var(--ik-radius, 6px);
		transition: outline-color 0.15s ease;
		outline: 1px dashed transparent;
		outline-offset: 2px;
	}
	.iikiti-region-locked:hover,
	.iikiti-region-locked:focus-visible {
		outline-color: color-mix(in srgb, var(--ik-accent, #a6613c) 65%, transparent);
	}
	.iikiti-region-locked__badge {
		position: absolute;
		top: 4px;
		left: 4px;
		z-index: 3;
		padding: 2px 8px;
		border-radius: 999px;
		background: color-mix(in srgb, var(--ik-accent, #a6613c) 92%, transparent);
		color: #fff;
		font-size: 11px;
		font-weight: 600;
		opacity: 0;
		transition: opacity 0.15s ease;
		pointer-events: none;
	}
	.iikiti-region-locked:hover .iikiti-region-locked__badge,
	.iikiti-region-locked:focus-visible .iikiti-region-locked__badge {
		opacity: 1;
	}
</style>
