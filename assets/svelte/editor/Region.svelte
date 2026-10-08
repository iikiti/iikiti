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
	/** Only the main content region gets the empty-state call to action. */
	const isMain = $derived(regionId === 'main');

	function activate() {
		onActivate?.(regionId);
	}

	function openAddBlock(e: MouseEvent) {
		e.stopPropagation();
		e.preventDefault();
		openAddBlockDialog({ regionId, parentId: null, allowedTypes: regionAllowed });
	}
</script>

{#if editable}
	{#if nodes.length === 0 && !isMain}
		<!-- Empty shells (header, footer, sidebars, dialogs) render nothing in the editor. -->
	{:else if nodes.length === 0}
		<button
			type="button"
			class="iikiti-region-empty"
			data-region-empty={regionId}
			aria-label="Add block to {name || regionId}"
			title="Add block"
			onclick={openAddBlock}
		>
			<span class="iikiti-region-empty__plus" aria-hidden="true">+</span>
		</button>
	{:else}
		<div class="iikiti-region-content" data-region-content={regionId}>
			{#each nodes as node, i (node.id)}
				<BlockView {node} {regionId} parentId={null} index={i} />
			{/each}
		</div>

		<div class="iikiti-region-controls" data-region-controls={regionId}>
			<button
				class="iikiti-btn iikiti-btn--add"
				title="Add block to {regionId}"
				aria-label="Add block to {regionId}"
				onclick={openAddBlock}
			>
				<span class="iikiti-btn__icon">+</span>
			</button>
		</div>
	{/if}
{:else if nodes.length === 0 && !isMain}
	<!-- Locked empty shells are invisible; no placeholder text. -->
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
		</div>
	</div>
{/if}

<style>
	.iikiti-region-content {
		min-height: 2rem;
		position: relative;
	}
	/* Dashed call-to-action shown only for an empty main content region. */
	.iikiti-region-empty {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 100%;
		min-height: 8rem;
		border: 2px dashed color-mix(in srgb, var(--ik-accent, #a6613c) 55%, transparent);
		border-radius: var(--ik-radius, 8px);
		background: transparent;
		cursor: pointer;
		transition: border-color 0.15s ease, background-color 0.15s ease;
	}
	.iikiti-region-empty:hover,
	.iikiti-region-empty:focus-visible {
		border-color: var(--ik-accent, #a6613c);
		background: color-mix(in srgb, var(--ik-accent, #a6613c) 6%, transparent);
	}
	.iikiti-region-empty__plus {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 48px;
		height: 48px;
		border-radius: 50%;
		font-size: 28px;
		line-height: 1;
		color: #fff;
		background: var(--ik-accent, #a6613c);
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
		width: 36px;
		height: 36px;
		padding: 0;
		min-width: 36px;
		font-size: 1rem;
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
		z-index: var(--iikiti-z-editor-canvas);
		padding: 2px 8px;
		border-radius: 999px;
		background: color-mix(in srgb, var(--ik-accent, #a6613c) 92%, transparent);
		color: #fff;
		font-size: 1rem;
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
