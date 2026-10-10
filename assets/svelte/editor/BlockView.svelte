<script lang="ts">
	import { onDestroy } from 'svelte';
	import { get } from 'svelte/store';
	import {
		selected,
		select,
		registerBlock,
		deleteBlock,
		moveBlock,
		blockTypes,
		tree,
		regions,
		openAddBlockDialog,
		allowedChildTypes,
		searchNode,
		iconSvgs,
		iconGlyphs,
	} from './state';
	import type { BlockNode } from './state';
	import Popover from '$components/Popover.svelte';
	import Icon from '$components/Icon.svelte';
	// Self-import: nested block previews recurse into this component (Svelte 5
	// replaces the deprecated <svelte:self> with an explicit self-import).
	// eslint-disable-next-line import/no-self-import -- intentional recursion
	import BlockView from './BlockView.svelte';

	let {
		node,
		regionId,
		readonly = false,
		parentId = null,
		index = 0,
		compact = false,
	}: {
		node: BlockNode;
		regionId: string;
		readonly?: boolean;
		/** Parent container block id, or null when the node sits at region root. */
		parentId?: string | null;
		/** Index of this node within its parent's child list (for gap insertion). */
		index?: number;
		/** Smaller affordances for inline children (e.g. text inside a heading). */
		compact?: boolean;
	} = $props();
	let self = $state<HTMLDivElement | null>(null);

	const isSelected = $derived(!readonly && $selected === node.id);

	$effect(() => {
		if (self && !readonly) {
			self.dataset.blockId = node.id;
			self.dataset.blockType = node.type;
			registerBlock(node.id, self);
		}
	});

	onDestroy(() => {
		if (!readonly) registerBlock(node.id, null);
	});

	function pick(ev: MouseEvent) {
		ev.stopPropagation();
		select(node.id);
	}

	let menuAnchor: HTMLElement | null = $state(null);

	const schema = $derived($blockTypes[node.type] as Record<string, unknown> | undefined);
	const acceptsChildren = $derived(Boolean(schema?.acceptsChildren));
	const childTypes = $derived.by(() => {
		const bt = $blockTypes;
		const s = bt?.[node.type];
		if (!s) return [];
		if (s.allowedChildTypes === null || s.allowedChildTypes === undefined) {
			return s.acceptsChildren ? Object.keys(bt ?? {}) : [];
		}
		return (s.allowedChildTypes as string[]) ?? [];
	});

	/** H2–H6 only; anything else renders as H2 (mirrors heading.twig). */
	function headingLevel(n: BlockNode): number {
		const level = Number(n.content?.level ?? 2);
		return level >= 2 && level <= 6 ? level : 2;
	}

	/** Tag allowlist for inline text children (preview copy; the server allowlist is CoreBlockTypeProvider::INLINE_TAGS). */
	const INLINE_TAGS = ['span', 'em', 'strong', 'b', 'u', 'i', 'small', 'code', 'mark'];

	function deleteNode(ev: MouseEvent) {
		ev.stopPropagation();
		ev.preventDefault();
		deleteBlock(node.id);
		menuAnchor = null;
		select(null);
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

	/**
	 * Insertable types at the parent of this block: the region's `allowed` list at
	 * root level, otherwise the parent block's `allowedChildTypes` (empty = any).
	 */
	function parentAllowedTypes(): string[] {
		if (!parentId) {
			// Root level accepts containers only (RootContainerRule).
			return ['container'];
		}
		const parent = searchNode(parentId);
		if (!parent) return [];
		return allowedChildTypes(parent.type, get(blockTypes));
	}

	/**
	 * Open the shared Add block dialog at the gap before (`index`) or after
	 * (`index + 1`) this block.
	 */
	function openInsertGap(position: number) {
		openAddBlockDialog({
			regionId,
			parentId,
			position,
			allowedTypes: parentAllowedTypes(),
		});
	}

	function openAddChild(ev: MouseEvent) {
		ev.stopPropagation();
		ev.preventDefault();
		openAddChildDialog();
		menuAnchor = null;
	}

	/** Opens the Add block dialog appending a child to this block. */
	function openAddChildDialog() {
		openAddBlockDialog({
			regionId,
			parentId: node.id,
			position: node.children?.length ?? 0,
			allowedTypes: childTypes,
		});
	}

	function openAddChildFromSlot(ev: MouseEvent) {
		ev.stopPropagation();
		ev.preventDefault();
		openAddChildDialog();
	}
</script>

{#snippet childSlot(compactSlot: boolean)}
	<button
		type="button"
		class="iikiti-child-slot"
		class:iikiti-child-slot--compact={compactSlot}
		data-child-slot={node.id}
		aria-label="Add child to {node.type}"
		title="Add child"
		onclick={openAddChildFromSlot}
	>
		<Icon name="plus" size={compactSlot ? 10 : 14} />
		<span class="iikiti-child-slot__label">Add child</span>
	</button>
{/snippet}

{#snippet preview()}
	{#if node.type === 'text'}
		{@html node.content?.content ?? ''}
	{:else if node.type === 'heading'}
		<svelte:element
			this={'h' + headingLevel(node)}
			class="iikiti-heading-preview"
			data-block-children
		>{node.content?.text ?? ''}{#each node.children ?? [] as child, i (child.id)}<BlockView node={child} {regionId} {readonly} parentId={node.id} index={i} compact />{/each}{#if acceptsChildren && !readonly}{@render childSlot(true)}{/if}</svelte:element>
	{:else if node.type === 'inline_text'}
		{@const tag = String(node.content?.tag ?? 'plain')}
		{#if tag !== 'plain' && INLINE_TAGS.includes(tag)}
			<svelte:element this={tag} class="iikiti-inline-text">{node.content?.text ?? ''}</svelte:element>
		{:else}
			{node.content?.text ?? ''}
		{/if}
	{:else if node.type === 'image'}
		{#if node.content?.source?.url}
			<img src={node.content.source.url} alt={node.content.alt ?? ''} class="iikiti-image" />
		{:else}<em class="iikiti-block--placeholder">Image URL missing</em> {/if}
	{:else if node.type === 'container'}
		<div class="iikiti-container" data-block-children>
			{#each node.children ?? [] as child, i (child.id)}<BlockView node={child} {regionId} {readonly} parentId={node.id} index={i} />{/each}
			{#if acceptsChildren && !readonly}{@render childSlot(false)}{/if}
		</div>
	{:else if node.type === 'video_embed'}
		<iframe src={node.content?.url} title="Embedded content" class="iikiti-embed__iframe" allowfullscreen loading="lazy"></iframe>
	{:else if node.type === 'social_embed'}
		{#if node.content?.url}<a href={node.content.url} class="iikiti-embed--link-card">{node.content.url}</a>{/if}
	{:else if node.type === 'query'}
		<div class="iikiti-query-preview" data-block-children>
			{#each node.children ?? [] as child, i (child.id)}<BlockView node={child} {regionId} {readonly} parentId={node.id} index={i} />{/each}
			{#if (node.children ?? []).length === 0}
				<em class="iikiti-block--placeholder">Query block (preview via API)</em>
			{:else}
				<em class="iikiti-query-preview__hint">Children repeat for every query result</em>
			{/if}
			{#if acceptsChildren && !readonly}{@render childSlot(false)}{/if}
		</div>
	{:else if node.type === 'dynamic'}
		<em class="iikti-block--placeholder">Dynamic content region</em>
	{:else if node.type === 'icon'}
		<!-- Server-generated markup only (trusted, escaped). Unknown names render nothing, as on the public site. -->
		{@const iconRef = String(node.content?.name ?? '')}
		<span class="iikiti-icon-block" data-block-icon={iconRef}>{@html (node.content?.renderer === 'font' ? $iconGlyphs[iconRef] : $iconSvgs[iconRef]) ?? ''}</span>
	{:else}
		<em class="iikiti-block--placeholder">Unknown block type</em>
	{/if}
{/snippet}

{#if readonly}
	<div bind:this={self} class="iikiti-block-preview iikiti-block-preview--readonly" data-block-node data-block-readonly>
		{@render preview()}
	</div>
{:else}
	<div
		bind:this={self}
		class:selected={isSelected}
		class="iikiti-block-preview"
		class:compact
		data-block-node
		tabindex="0"
		role="button"
		aria-label="Select block"
		onclick={pick}
		onkeydown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(e); } }}
	>
		{@render preview()}
		<div class="iikiti-outline iikiti-outline--selected" aria-hidden="true"></div>

		<button
			type="button"
			class="iikiti-insert-btn iikiti-insert-btn--before"
			title="Insert block before"
			aria-label="Insert block before"
			onclick={(e) => { e.stopPropagation(); e.preventDefault(); openInsertGap(index); }}
		>
			<Icon name="plus" size={compact ? 10 : 12} />
		</button>
		<button
			type="button"
			class="iikiti-insert-btn iikiti-insert-btn--after"
			title="Insert block after"
			aria-label="Insert block after"
			onclick={(e) => { e.stopPropagation(); e.preventDefault(); openInsertGap(index + 1); }}
		>
			<Icon name="plus" size={compact ? 10 : 12} />
		</button>

		<div class="iikiti-context-menu">
			<button class="iikiti-btn iikiti-btn--sm" title="Select block" onclick={() => { select(node.id); }}>✏</button>
			<button class="iikiti-btn iikiti-btn--sm" title="More actions" onclick={(e) => { e.stopPropagation(); e.preventDefault(); menuAnchor = e.currentTarget as HTMLElement; }}>⋮</button>
		</div>
		{#if menuAnchor}
			<Popover anchor={menuAnchor} placement="bottom-end" closeOnOutside onclose={() => (menuAnchor = null)}>
				<div class="iikiti-block-menu">
					{#if acceptsChildren}
						<button class="iikiti-block-menu__item" onclick={openAddChild}>Add child…</button>
					{/if}
					<button class="iikiti-block-menu__item" onclick={moveUp}>Move up</button>
					<button class="iikiti-block-menu__item" onclick={moveDown}>Move down</button>
					<button class="iikiti-block-menu__item iikiti-block-menu__item--destructive" onclick={deleteNode}>Delete</button>
				</div>
			</Popover>
		{/if}
	</div>
{/if}

<style>
	.iikiti-block-preview[data-block-node] { position: relative; }
	.iikiti-block-preview.selected { outline: 2px solid #3b82f6; outline-offset: 2px; }
	.iikiti-block-preview:hover { outline: 1px dashed #93c5fd; outline-offset: 1px; }
	.iikiti-block-preview:active { outline: 2px solid #60a5fa; outline-offset: 2px; }
	.iikiti-block-preview--readonly { pointer-events: none; }
	.iikiti-outline { position: absolute; inset: 0; border-radius: 3px; pointer-events: none; }
	.iikiti-outline--selected { border: 2px dashed #3b82f6; }

	/* ── Before/after insertion pluses ──
	 * Circular buttons sitting on the top/bottom edge of the block box, half
	 * outside so they read as gap affordances between siblings. Revealed while
	 * the block (or a button itself) is hovered/focused; always visible on
	 * touch devices. `compact` shrinks them for inline children (headings). */
	.iikiti-insert-btn {
		position: absolute;
		left: 50%;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 20px;
		height: 20px;
		padding: 0;
		border: 1px solid var(--ik-panel-border, #d1d5db);
		border-radius: 50%;
		background: var(--ik-panel-bg, #ffffff);
		color: var(--ik-panel-text, #111827);
		cursor: pointer;
		opacity: 0;
		pointer-events: none;
		box-shadow: 0 1px 4px rgba(0, 0, 0, 0.18);
		transition: opacity 0.15s ease, background-color 0.12s ease, color 0.12s ease, border-color 0.12s ease;
		z-index: var(--iikiti-z-editor-controls);
	}
	/* Sit fully outside the block: "before" above the top edge, "after" below the
	 * bottom edge, both centred on the block's horizontal midpoint. */
	.iikiti-insert-btn--before { bottom: 100%; margin-bottom: 4px; transform: translateX(-50%); }
	.iikiti-insert-btn--after { top: 100%; margin-top: 4px; transform: translateX(-50%); }
	/* Invisible hit zones bridging the block and each outside button, so moving
	 * the pointer toward a button does not drop the hover state. */
	.iikiti-block-preview[data-block-node]::before,
	.iikiti-block-preview[data-block-node]::after {
		content: '';
		position: absolute;
		left: 0;
		right: 0;
		height: 40px;
		pointer-events: none;
	}
	.iikiti-block-preview[data-block-node]::before { bottom: 100%; }
	.iikiti-block-preview[data-block-node]::after { top: 100%; }
	.iikiti-block-preview:hover::before,
	.iikiti-block-preview:hover::after {
		pointer-events: auto;
	}

	.iikiti-block-preview:hover .iikiti-insert-btn,
	.iikiti-insert-btn:hover,
	.iikiti-insert-btn:focus-visible,
	.iikiti-insert-btn:focus-within {
		opacity: 1;
		pointer-events: auto;
	}
	.iikiti-insert-btn:hover,
	.iikiti-insert-btn:focus-visible {
		color: var(--ik-accent, #a6613c);
		border-color: color-mix(in srgb, var(--ik-accent, #a6613c) 70%, var(--ik-panel-border, #d1d5db));
	}
	:global(.iikiti-touch) .iikiti-insert-btn {
		opacity: 1;
		pointer-events: auto;
	}
	.iikiti-block-preview.compact .iikiti-insert-btn {
		width: 15px;
		height: 15px;
	}

	/* ── Child slot ──
	 * Dashed add block shown inside every child-accepting block, mirroring the
	 * empty region call to action so an empty or populated container always
	 * exposes "Add child" without the hidden context menu. */
	.iikiti-child-slot {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 6px;
		width: 100%;
		min-height: 3rem;
		margin-top: 4px;
		padding: 8px;
		border: 2px dashed color-mix(in srgb, var(--ik-accent, #a6613c) 55%, transparent);
		border-radius: var(--ik-radius, 8px);
		background: transparent;
		color: var(--ik-accent, #a6613c);
		font-size: 1rem;
		cursor: pointer;
		transition: border-color 0.15s ease, background-color 0.15s ease;
	}
	.iikiti-child-slot:hover,
	.iikiti-child-slot:focus-visible {
		border-color: var(--ik-accent, #a6613c);
		background: color-mix(in srgb, var(--ik-accent, #a6613c) 6%, transparent);
	}
	.iikiti-child-slot--compact {
		display: inline-flex;
		width: auto;
		min-height: 0;
		margin: 0 0 0 4px;
		padding: 0 4px;
		border-width: 1px;
		vertical-align: middle;
	}
	.iikiti-child-slot__label {
		font-weight: 600;
	}
	.iikiti-child-slot--compact .iikiti-child-slot__label {
		display: none;
	}

	/* Query blocks render their children once in the canvas (per-result
	 * repetition happens server-side); the hint states that. */
	.iikiti-query-preview {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}
	.iikiti-query-preview__hint {
		font-size: 1rem;
		color: var(--ik-panel-text-muted, #6b7280);
		font-style: italic;
	}

	.iikiti-context-menu {
		position: absolute;
		top: 2px;
		right: 2px;
		display: flex;
		gap: 2px;
		opacity: 0;
		transition: opacity 0.15s ease;
		pointer-events: auto;
		z-index: var(--iikiti-z-editor-controls);
	}
	.iikiti-block-preview:hover .iikiti-context-menu,
	.iikiti-block-preview.selected .iikiti-context-menu {
		opacity: 1;
	}
	:global(.iikiti-touch) .iikiti-context-menu {
		opacity: 1;
	}
	.iikiti-btn--sm {
		width: 36px;
		height: 36px;
		padding: 0 2px;
		min-width: 36px;
		font-size: 1rem;
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
		font-size: 1rem;
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
