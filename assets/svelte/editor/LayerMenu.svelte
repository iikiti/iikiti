<script lang="ts">
	import { regions, tree, blockTypes, selected, select, getBlockElement, layersOpen, activeRegion, moveBlock } from './state';
	import type { BlockNode } from './state';
	import { computeDropPlan, resolveDropZone } from './layerDrop';
	import { duplicateUserIds } from './blockClipboard';
	import {
		hasChildSlot,
		isAllExpanded,
		parseStoredExpanded,
		pruneExpanded,
		setAll,
		toggleNode,
		toggleSubtree,
	} from './layerTree';
	import { resolveBlockActions } from './blockActions';
	import Dialog from '$components/Dialog.svelte';
	import Icon from '$components/Icon.svelte';
	import ContextMenu from '$components/ContextMenu.svelte';
	import type { ContextMenuItem } from '$components/ContextMenu.svelte';

	/** Right-click menu state. `nodeId` is null when opened on the empty area. */
	let menu = $state<{ x: number; y: number; nodeId: string | null } | null>(null);

	function openContextMenu(event: MouseEvent, node: BlockNode | null) {
		event.preventDefault();
		event.stopPropagation();
		if (node) select(node.id);
		menu = { x: event.clientX, y: event.clientY, nodeId: node?.id ?? null };
	}

	/** Items for the open menu, resolved from the block-action registry for its context. */
	const menuItems = $derived.by((): ContextMenuItem[] => {
		if (!menu) return [];
		const nodeId = menu.nodeId;
		const node = nodeId ? findNodeInTree($tree, nodeId) : null;
		const region = $activeRegion ?? '';
		const location = node ? locateParent($tree, nodeId) : { parentId: null, index: null };
		return resolveBlockActions({
			node,
			parentId: location.parentId,
			regionId: region,
			index: location.index,
		}).map((item) => ({ ...item, onSelect: () => { item.onSelect(); } }));
	});

	function closeContextMenu() {
		menu = null;
	}

	/** Finds a node by id across all regions (kept local so the menu stays pure). */
	function findNodeInTree(t: Record<string, BlockNode[]>, id: string): BlockNode | null {
		for (const regionId of Object.keys(t)) {
			const hit = searchList(t[regionId] ?? [], id);
			if (hit) return hit;
		}
		return null;
	}

	function searchList(nodes: BlockNode[], id: string): BlockNode | null {
		for (const n of nodes) {
			if (n.id === id) return n;
			const hit = searchList(n.children ?? [], id);
			if (hit) return hit;
		}
		return null;
	}

	/** Parent id and index of a node for the action context. */
	function locateParent(t: Record<string, BlockNode[]>, id: string | null): { parentId: string | null; index: number | null } {
		if (!id) return { parentId: null, index: null };
		for (const regionId of Object.keys(t)) {
			const found = locateInList(t[regionId] ?? [], id, null);
			if (found) return found;
		}
		return { parentId: null, index: null };
	}

	function locateInList(nodes: BlockNode[], id: string, parentId: string | null): { parentId: string | null; index: number } | null {
		for (let index = 0; index < nodes.length; index++) {
			if (nodes[index].id === id) return { parentId, index };
			const nested = locateInList(nodes[index].children ?? [], id, nodes[index].id);
			if (nested) return nested;
		}
		return null;
	}

	/** User-defined ids used by more than one block; these rows show a warning. */
	const duplicateIds = $derived(duplicateUserIds($tree));

	/** localStorage key for expansion; the Dialog persists it through getState/setState. */
	const EXPANDED_STORAGE_KEY = 'iikiti.layers.expanded';

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

	/** Id of the row currently being dragged, or null when no drag is active. */
	let draggingId = $state<string | null>(null);
	/** Row under the pointer plus the zone it would drop into. */
	let dropTarget = $state<{ id: string; zone: 'above' | 'below' | 'inside' } | null>(null);

	function isContainer(type: string): boolean {
		return Boolean($blockTypes[type]?.['acceptsChildren']);
	}

	function onDragStart(event: DragEvent, id: string) {
		draggingId = id;
		if (event.dataTransfer) {
			event.dataTransfer.effectAllowed = 'move';
			// Firefox only starts a drag when some data is set.
			event.dataTransfer.setData('text/plain', id);
		}
		event.stopPropagation();
	}

	function onDragOver(event: DragEvent, node: BlockNode) {
		if (!draggingId) return;
		event.preventDefault();
		event.stopPropagation();
		const row = event.currentTarget as HTMLElement;
		const rect = row.getBoundingClientRect();
		const zone = resolveDropZone(event.clientY - rect.top, rect.height, isContainer(node.type));
		const plan = computeDropPlan({ tree: $tree, blockTypes: $blockTypes, draggedId: draggingId, targetId: node.id, zone });
		dropTarget = plan ? { id: node.id, zone } : null;
	}

	/**
	 * Rows are nested, so `dragleave` also fires on a parent row when the pointer
	 * moves into one of its children. Only clear the indicator when the pointer
	 * has actually left this row's box.
	 */
	function onDragLeave(event: DragEvent, node: BlockNode) {
		if (dropTarget?.id !== node.id) return;
		const row = event.currentTarget as HTMLElement;
		const next = event.relatedTarget as Node | null;
		if (next && row.contains(next)) return;
		dropTarget = null;
	}

	function onDrop(event: DragEvent, node: BlockNode) {
		event.preventDefault();
		event.stopPropagation();
		const sourceId = draggingId;
		const zone = dropTarget?.id === node.id ? dropTarget.zone : null;
		resetDrag();
		if (!sourceId || !zone) return;
		const plan = computeDropPlan({ tree: $tree, blockTypes: $blockTypes, draggedId: sourceId, targetId: node.id, zone });
		if (plan) moveBlock(sourceId, plan.toParent, plan.position);
	}

	function resetDrag() {
		draggingId = null;
		dropTarget = null;
	}

	/** Ids of containers currently open. Owned here; persisted by the Dialog. */
	let expanded = $state<Set<string>>(new Set());

	/** Restored from storage by the Dialog on open; pruned to live containers. */
	function restoreExpanded(stored: unknown) {
		const raw = stored === undefined ? null : JSON.stringify(stored);
		expanded = pruneExpanded(parseStoredExpanded(raw), $tree, $blockTypes);
	}

	function currentExpanded(): string[] {
		return [...expanded];
	}

	const allExpanded = $derived(isAllExpanded(expanded, $tree, $blockTypes));

	function toggleChildren(id: string) {
		expanded = toggleNode(expanded, id);
		notifyStateChanged();
	}

	/** Double-click: open or close the row and its whole subtree. */
	function onRowDoubleClick(node: BlockNode) {
		const open = !expanded.has(node.id);
		expanded = toggleSubtree(expanded, node, open, $blockTypes);
		notifyStateChanged();
	}

	function toggleAll() {
		expanded = setAll($tree, $blockTypes, !allExpanded);
		notifyStateChanged();
	}

	let dialog: { notifyStateChanged: () => void } | undefined;
	function notifyStateChanged() {
		dialog?.notifyStateChanged();
	}
</script>

<Dialog
	bind:this={dialog}
	title="Layers"
	storageKey="editor.layers"
	width={280}
	height={420}
	minWidth={220}
	minHeight={200}
	persistState
	getState={currentExpanded}
	setState={restoreExpanded}
	onClose={() => layersOpen.set(false)}
>
	{#snippet headerActions()}
		<button
			type="button"
			class="iikiti-layer-tree__toggle-all"
			aria-label={allExpanded ? 'Collapse all layers' : 'Expand all layers'}
			title={allExpanded ? 'Collapse all' : 'Expand all'}
			onclick={toggleAll}
		>
			<Icon name="chevrons-down-up" size={16} />
		</button>
	{/snippet}
	<div
		class="iikiti-layer-tree"
		role="tree"
		tabindex="0"
		aria-label="Page layers"
		data-tour="editor.layers"
		oncontextmenu={(event) => {
			if (event.target === event.currentTarget) openContextMenu(event, null);
		}}
	>
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

{#if menu}
	<ContextMenu
		items={menuItems}
		x={menu.x}
		y={menu.y}
		ariaLabel="Layer actions"
		onclose={closeContextMenu}
	/>
{/if}

{#snippet row(node: BlockNode, depth: number)}
	{@const isOpen = expanded.has(node.id)}
	{@const expandable = hasChildSlot(node.type, $blockTypes)}
	<div
		class="iikiti-layer-tree__row"
		role="treeitem"
		tabindex="-1"
		aria-selected={$selected === node.id}
		aria-expanded={expandable ? isOpen : undefined}
		aria-level={depth}
		class:dragging={draggingId === node.id}
		class:drop-above={dropTarget?.id === node.id && dropTarget.zone === 'above'}
		class:drop-below={dropTarget?.id === node.id && dropTarget.zone === 'below'}
		class:drop-inside={dropTarget?.id === node.id && dropTarget.zone === 'inside'}
		draggable="true"
		oncontextmenu={(event) => openContextMenu(event, node)}
		ondragstart={(event) => onDragStart(event, node.id)}
		ondragover={(event) => onDragOver(event, node)}
		ondragleave={(event) => onDragLeave(event, node)}
		ondrop={(event) => onDrop(event, node)}
		ondragend={resetDrag}
	>
		<div
			class="iikiti-layer-tree__item"
			class:selected={$selected === node.id}
			style="padding-left: {8 + depth * 14}px"
		>
			{#if expandable}
				<button
					type="button"
					class="iikiti-layer-tree__chevron"
					aria-label={isOpen ? 'Collapse' : 'Expand'}
					onclick={(event) => {
						event.stopPropagation();
						toggleChildren(node.id);
					}}
				>
					<Icon name={isOpen ? 'chevron-down' : 'chevron-right'} size={12} />
				</button>
			{:else}
				<span class="iikiti-layer-tree__chevron-spacer" aria-hidden="true"></span>
			{/if}
			<!--
				A press on a <button> is a click, not a drag, so the label is a plain
				element: the native drag starts from the draggable row. Keyboard
				selection is handled by onkeydown; the row stays the tree item.
			-->
			<div
				class="iikiti-layer-tree__select"
				role="button"
				tabindex="0"
				onclick={() => pick(node.id)}
				onkeydown={(event) => {
					if (event.key === 'Enter' || event.key === ' ') {
						event.preventDefault();
						pick(node.id);
					}
				}}
				ondblclick={() => {
					if (expandable) onRowDoubleClick(node);
				}}
			>
				<span class="iikiti-layer-tree__icon" aria-hidden="true"><Icon name={iconFor(node.type)} size={14} /></span>
				<span class="iikiti-layer-tree__label">{labelFor(node)}</span>
			</div>
			{#if duplicateIds.has(node.id)}
				<span
					class="iikiti-layer-tree__duplicate"
					role="img"
					aria-label="Duplicate block id"
					title="Duplicate block id “{node.id}”: another block uses the same id. Rename one of them in the block’s element settings."
				><Icon name="alert-circle" size={14} /></span>
			{/if}
		</div>
	</div>
	{#if expandable && isOpen && node.children && node.children.length}
		<div role="group">
			{#each node.children as child (child.id)}
				{@render row(child, depth + 1)}
			{/each}
		</div>
	{/if}
{/snippet}

<style>
	.iikiti-layer-tree {
		display: flex;
		flex-direction: column;
		min-height: 100%;
	}

	.iikiti-layer-tree__duplicate {
		display: inline-flex;
		align-items: center;
		color: var(--ik-warning, #b8860b);
		cursor: help;
		margin-left: 4px;
	}

	.iikiti-layer-tree__region {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		padding: 6px 8px;
		font-size: 1rem;
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

	.iikiti-layer-tree__toggle-all {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 26px;
		height: 26px;
		border: none;
		border-radius: 6px;
		background: transparent;
		color: var(--ik-panel-text-muted, #6b7280);
		cursor: pointer;
	}

	.iikiti-layer-tree__toggle-all:hover {
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 12%, transparent);
		color: var(--ik-panel-text, #111827);
	}

	.iikiti-layer-tree__item {
		display: flex;
		align-items: center;
		gap: 2px;
		width: 100%;
		padding-top: 4px;
		padding-right: 8px;
		padding-bottom: 4px;
		border-radius: 6px;
		color: var(--ik-panel-text, #111827);
		font-size: 1rem;
		transition: background-color 0.1s ease;
	}

	.iikiti-layer-tree__item:hover {
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 8%, transparent);
	}

	.iikiti-layer-tree__item.selected {
		background: color-mix(in srgb, var(--ik-accent, #a6613c) 16%, transparent);
		color: var(--ik-accent-hover, #945231);
	}

	.iikiti-layer-tree__chevron,
	.iikiti-layer-tree__chevron-spacer {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
		width: 18px;
		height: 22px;
	}

	.iikiti-layer-tree__chevron {
		border: none;
		border-radius: 4px;
		background: transparent;
		color: var(--ik-panel-text-muted, #6b7280);
		cursor: pointer;
	}

	.iikiti-layer-tree__chevron:hover {
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 12%, transparent);
	}

	.iikiti-layer-tree__select {
		display: flex;
		align-items: center;
		gap: 6px;
		flex: 1;
		min-width: 0;
		padding: 0;
		border: none;
		background: transparent;
		color: inherit;
		font: inherit;
		text-align: left;
		cursor: pointer;
	}

	/* Drop feedback: a 2px accent bar above/below, a tinted box for nesting. */
	.iikiti-layer-tree__row.dragging {
		opacity: 0.45;
	}

	.iikiti-layer-tree__row.drop-above {
		box-shadow: inset 0 2px 0 var(--ik-accent, #a6613c);
	}

	.iikiti-layer-tree__row.drop-below {
		box-shadow: inset 0 -2px 0 var(--ik-accent, #a6613c);
	}

	.iikiti-layer-tree__row.drop-inside {
		outline: 2px solid var(--ik-accent, #a6613c);
		outline-offset: -2px;
	}

	.iikiti-layer-tree__icon {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
		width: 28px;
		height: 28px;
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
