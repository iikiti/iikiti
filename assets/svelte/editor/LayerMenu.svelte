<script lang="ts">
	import { regions, tree, blockTypes, selected, select, getBlockElement, layersOpen, activeRegion } from './state';
	import type { BlockNode } from './state';
	import Dialog from '$components/Dialog.svelte';
	import Icon from '$components/Icon.svelte';

	/**
	 * "Layers" navigator — floating, draggable dialog listing the structure of
	 * the region currently being edited (the active region, defaulting to the
	 * page's `main` content). Clicking an entry selects the block in the editor
	 * canvas and scrolls it into view. Built on the generic Dialog so any
	 * plugin/theme can reuse the same draggable dialog for its own tools.
	 */
	const TYPE_ICONS: Record<string, string> = {
		container: 'box',
		heading: 'heading',
		inline_text: 'type',
		text: 'type',
		image: 'image',
		video_embed: 'video',
		social_embed: 'link',
		query: 'database',
		dynamic: 'activity',
	};

	const activeRegionInfo = $derived($regions.find((r) => r.id === $activeRegion));
	const activeRegionNodes = $derived($tree[$activeRegion] ?? []);

	function iconFor(type: string): string {
		return TYPE_ICONS[type] ?? 'box';
	}

	function labelFor(node: BlockNode): string {
		const label = $blockTypes[node.type]?.['label'];
		if (label) return String(label);
		// Fall back to a hint of the block content for text-like blocks.
		const content = node.content as Record<string, unknown> | undefined;
		const hint = content?.['text'] ?? content?.['content'];
		return hint ? String(hint).trim().slice(0, 42) : node.type;
	}

	function pick(id: string) {
		select(id);
		getBlockElement(id)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}
</script>

<Dialog
	title="Layers"
	storageKey="editor.layers"
	width={280}
	height={420}
	minWidth={220}
	minHeight={200}
	onClose={() => layersOpen.set(false)}
>
	<div class="iikiti-layer-tree" role="tree" aria-label="Page layers" data-tour="editor.layers">
		{#if activeRegionInfo}
			<div class="iikiti-layer-tree__region">
				<span class="iikiti-layer-tree__region-name">{activeRegionInfo.name || activeRegionInfo.id}</span>
				{#if activeRegionNodes.length > 0}
					<span class="iikiti-layer-tree__count">{activeRegionNodes.length}</span>
				{:else}
					<span class="iikiti-layer-tree__count iikiti-layer-tree__count--empty">empty</span>
				{/if}
			</div>
		{/if}
		{#each activeRegionNodes as node (node.id)}
			{@render row(node, 1)}
		{/each}
	</div>
</Dialog>

{#snippet row(node: BlockNode, depth: number)}
	<button
		type="button"
		class="iikiti-layer-tree__item"
		class:selected={$selected === node.id}
		role="treeitem"
		aria-selected={$selected === node.id}
		style="padding-left: {8 + depth * 14}px"
		onclick={() => pick(node.id)}
	>
		<span class="iikiti-layer-tree__icon" aria-hidden="true"><Icon name={iconFor(node.type)} size={14} /></span>
		<span class="iikiti-layer-tree__label">{labelFor(node)}</span>
	</button>
	{#if node.children && node.children.length}
		{#each node.children as child (child.id)}
			{@render row(child, depth + 1)}
		{/each}
	{/if}
{/snippet}

<style>
	.iikiti-layer-tree {
		display: flex;
		flex-direction: column;
		min-height: 100%;
	}

	.iikiti-layer-tree__region {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		padding: 6px 8px;
		font-size: 11px;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		color: var(--ik-panel-text-muted, #6b7280);
		border-bottom: 1px solid color-mix(in srgb, var(--ik-panel-border, #e5e7eb) 60%, transparent);
		margin-bottom: 2px;
	}

	.iikiti-layer-tree__count {
		flex-shrink: 0;
		padding: 0 6px;
		border-radius: 99px;
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 10%, transparent);
		line-height: 18px;
		font-weight: 500;
	}

	.iikiti-layer-tree__count--empty {
		background: transparent;
		font-weight: 400;
		letter-spacing: 0;
		text-transform: none;
	}

	.iikiti-layer-tree__item {
		display: flex;
		align-items: center;
		gap: 6px;
		width: 100%;
		padding-top: 4px;
		padding-right: 8px;
		padding-bottom: 4px;
		border: none;
		border-radius: 6px;
		background: transparent;
		color: var(--ik-panel-text, #111827);
		font-size: 12.5px;
		text-align: left;
		cursor: pointer;
		transition: background-color 0.1s ease;
	}

	.iikiti-layer-tree__item:hover {
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 8%, transparent);
	}

	.iikiti-layer-tree__item.selected {
		background: color-mix(in srgb, var(--ik-accent, #a6613c) 16%, transparent);
		color: var(--ik-accent-hover, #945231);
	}

	.iikiti-layer-tree__icon {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
		width: 20px;
		height: 20px;
		border-radius: 4px;
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 9%, transparent);
		color: var(--ik-panel-text-muted, #6b7280);
	}

	.iikiti-layer-tree__item.selected .iikiti-layer-tree__icon {
		background: color-mix(in srgb, var(--ik-accent-hover, #945231) 14%, transparent);
		color: var(--ik-accent-hover, #945231);
	}

	.iikiti-layer-tree__label {
		flex: 1;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
</style>
