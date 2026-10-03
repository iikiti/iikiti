<script lang="ts">
	import { get } from 'svelte/store';
	import { selected, blockTypes, regions, tree, moveBlock, pathToNode } from './state';
	import type { BlockNode } from './state';
	import Popover from '$components/Popover.svelte';

	/**
	 * Structure popover — a quick "where am I / reorder" view anchored to the
	 * selected block. It shows the breadcrumb path (region → ancestors → block)
	 * and moves the block up/down among its siblings. Advanced content/element/
	 * style editing lives in the settings sidebar.
	 */
	let { anchor }: { anchor: HTMLElement | null } = $props();

	const info = $derived.by(() => {
		const id = $selected;
		if (!id) return null;
		return pathToNode(id);
	});

	const node = $derived(info ? (info.path[info.path.length - 1] ?? null) : null);
	const parent = $derived(info && info.path.length > 1 ? info.path[info.path.length - 2] : null);

	const siblings = $derived.by(() => {
		if (!info) return [] as BlockNode[];
		const list = parent ? (parent.children ?? []) : ($tree[info.regionId] ?? []);
		return list as BlockNode[];
	});

	const index = $derived(node ? siblings.findIndex((n) => n.id === node.id) : -1);
	const canUp = $derived(index > 0);
	const canDown = $derived(index >= 0 && index < siblings.length - 1);

	function labelFor(n: BlockNode | null): string {
		if (!n) return '';
		const label = get(blockTypes)[n.type]?.['label'];
		if (label) return String(label);
		const content = n.content as Record<string, unknown> | undefined;
		const hint = content?.['text'] ?? content?.['content'];
		return hint ? String(hint).trim().slice(0, 24) : n.type;
	}

	function regionName(regionId: string): string {
		const r = get(regions).find((x) => x.id === regionId);
		return r?.name || regionId;
	}

	function move(delta: number) {
		if (!node || !info) return;
		const next = index + delta;
		if (next < 0 || next >= siblings.length) return;
		moveBlock(node.id, parent ? parent.id : null, next);
	}
</script>

{#if node && info}
	<Popover {anchor} placement="right" closeOnOutside={false} onclose={() => { }}>
		<div class="iikiti-structure" data-tour="editor.structure">
			<div class="iikiti-structure__crumbs" aria-label="Block path">
				<span class="iikiti-structure__crumb iikiti-structure__crumb--region">{regionName(info.regionId)}</span>
				{#each info.path as p, i (p.id)}
					<span class="iikiti-structure__sep" aria-hidden="true">›</span>
					<span class="iikiti-structure__crumb" class:current={i === info.path.length - 1}>{labelFor(p)}</span>
				{/each}
			</div>
			<div class="iikiti-structure__actions">
				<button
					type="button"
					class="iikiti-btn iikiti-btn--sm iikiti-structure__move"
					disabled={!canUp}
					title="Move up"
					onclick={() => move(-1)}
				>↑ Move up</button>
				<button
					type="button"
					class="iikiti-btn iikiti-btn--sm iikiti-structure__move"
					disabled={!canDown}
					title="Move down"
					onclick={() => move(1)}
				>↓ Move down</button>
			</div>
		</div>
	</Popover>
{/if}

<style>
	.iikiti-structure {
		display: flex;
		flex-direction: column;
		gap: 8px;
		min-width: 200px;
		max-width: 340px;
	}
	.iikiti-structure__crumbs {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 4px;
		font-size: 12.5px;
	}
	.iikiti-structure__crumb {
		color: var(--ik-panel-text-muted, #6b7280);
	}
	.iikiti-structure__crumb--region {
		font-weight: 600;
		text-transform: uppercase;
		font-size: 11px;
		letter-spacing: 0.04em;
	}
	.iikiti-structure__crumb.current {
		color: var(--ik-panel-text, #111827);
		font-weight: 600;
	}
	.iikiti-structure__sep {
		color: var(--ik-panel-text-muted, #9ca3af);
	}
	.iikiti-structure__actions {
		display: flex;
		gap: 6px;
	}
	.iikiti-structure__move:disabled {
		opacity: 0.45;
		cursor: default;
	}
</style>
