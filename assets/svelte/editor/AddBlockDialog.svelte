<script lang="ts">
	import {
		blockTypes,
		addBlockDialog,
		closeAddBlockDialog,
		addBlock,
		select,
		searchNode,
		pathToNode,
	} from './state';
	import ModalDialog from '$components/ModalDialog.svelte';
	import Icon from '$components/Icon.svelte';

	/**
	 * Shared "Add block" dialog. Opened through the `addBlockDialog` store in
	 * state.js by every entry point: the toolbar plus, the per-block before/after
	 * pluses, the region-level add button and the "Add child…" context-menu item.
	 * Replaces the old anchored BlockPalette popover so there is one add-block UI.
	 *
	 * Filtering: an explicit (non-empty) `allowedTypes` list limits the palette to
	 * those types (e.g. a `heading` offering only `inline_text`). An empty list
	 * means "any type", except `inline`-category types, which may only be inserted
	 * where a parent explicitly allows them.
	 */

	const context = $derived($addBlockDialog);
	const allowed = $derived(context?.allowedTypes ?? []);

	const grouped = $derived.by(() => {
		const bt = $blockTypes;
		if (!context || !bt) return [];
		const explicit = allowed.length > 0 ? new Set(allowed) : null;
		// Query blocks never nest: a query inside a query would re-execute per row.
		const insideQuery = context.parentId ? isInsideQuery(context.parentId) : false;
		const categoryOrder: Record<string, number> = { layout: 0, text: 1, media: 2, content: 3 };
		const list = Object.values(bt)
			.filter((t) => {
				const type = String(t.type);
				if (insideQuery && type === 'query') return false;
				if (explicit) return explicit.has(type);
				return String(t.category ?? '') !== 'inline';
			})
			.filter((t) => matchesSearch(t))
			.sort((a, b) => {
				const ca = categoryOrder[String(a.category ?? '')] ?? 99;
				const cb = categoryOrder[String(b.category ?? '')] ?? 99;
				if (ca !== cb) return ca - cb;
				return String(a.label ?? a.type).localeCompare(String(b.label ?? b.type));
			});
		const groups: Record<string, Array<Record<string, unknown>>> = {};
		for (const t of list) {
			const cat = String(t.category ?? 'other');
			(groups[cat] ??= []).push(t);
		}
		return Object.entries(groups);
	});

	let search = $state('');

	/** True when the parent block is a query or sits inside one. */
	function isInsideQuery(parentId: string): boolean {
		if (searchNode(parentId)?.type === 'query') return true;
		const found = pathToNode(parentId);
		return Boolean(found?.path.some((n) => n.type === 'query'));
	}

	/** Mirrors LayerMenu's type→icon mapping (same kebab-case Lucide names). */
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

	function iconFor(t: Record<string, unknown>): string {
		return TYPE_ICONS[String(t.type)] ?? 'box';
	}

	function matchesSearch(t: Record<string, unknown>): boolean {
		const q = search.trim().toLowerCase();
		if (!q) return true;
		const label = String(t.label ?? t.type).toLowerCase();
		return label.includes(q) || String(t.type).toLowerCase().includes(q);
	}

	function insert(type: string) {
		if (!context) return;
		const id = addBlock(context.regionId, context.parentId, type, context.position);
		select(id);
		closeAddBlockDialog();
	}
</script>

{#if context}
	<ModalDialog
		title="Add block"
		open
		width={380}
		onClose={closeAddBlockDialog}
	>
		<div class="iikiti-add-block" data-tour="editor.add-block">
			<input
				type="search"
				class="iikiti-add-block__search"
				placeholder="Search blocks…"
				aria-label="Search blocks"
				bind:value={search}
			/>
			<div class="iikiti-add-block__list" role="listbox" aria-label="Block types">
				{#if grouped.length === 0}
					<p class="iikiti-add-block__empty">No matching block types.</p>
				{/if}
				{#each grouped as [cat, types] (cat)}
					<div class="iikiti-add-block__group">
						<div class="iikiti-add-block__label">{cat}</div>
						{#each types as t (String(t.type))}
							<button
								type="button"
								class="iikiti-add-block__item"
								data-block-type={t.type}
								title={String(t.label ?? t.type)}
								onclick={() => insert(String(t.type))}
							>
								<span class="iikiti-add-block__icon" aria-hidden="true">
									<Icon name={iconFor(t)} size={14} />
								</span>
								<span class="iikiti-add-block__name">{String(t.label ?? t.type)}</span>
							</button>
						{/each}
					</div>
				{/each}
			</div>
		</div>
	</ModalDialog>
{/if}

<style>
	.iikiti-add-block {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.iikiti-add-block__search {
		width: 100%;
		box-sizing: border-box;
		padding: 6px 10px;
		font: inherit;
		font-size: 13px;
		border: 1px solid var(--ik-panel-border, #d1d5db);
		border-radius: 6px;
		background: var(--ik-panel-bg, #ffffff);
		color: var(--ik-panel-text, #111827);
	}
	.iikiti-add-block__search:focus-visible {
		outline: 2px solid color-mix(in srgb, var(--ik-accent, #a6613c) 60%, transparent);
		outline-offset: -1px;
	}
	.iikiti-add-block__list {
		display: flex;
		flex-direction: column;
		gap: 8px;
		max-height: 340px;
		overflow-y: auto;
	}
	.iikiti-add-block__empty {
		margin: 4px;
		font-size: 12.5px;
		color: var(--ik-panel-text-muted, #6b7280);
	}
	.iikiti-add-block__group {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
	.iikiti-add-block__label {
		font-size: 10px;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		color: var(--ik-panel-text-muted, #6b7280);
		padding: 2px 4px;
	}
	.iikiti-add-block__item {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 6px 8px;
		font: inherit;
		font-size: 13px;
		text-align: left;
		border: 1px solid transparent;
		border-radius: 6px;
		background: transparent;
		color: var(--ik-panel-text, #111827);
		cursor: pointer;
		transition:
			background-color 0.1s ease,
			border-color 0.1s ease;
	}
	.iikiti-add-block__item:hover,
	.iikiti-add-block__item:focus-visible {
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 8%, transparent);
		border-color: var(--ik-panel-border, #d1d5db);
		outline: none;
	}
	.iikiti-add-block__icon {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
		width: 22px;
		height: 22px;
		border-radius: 5px;
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 9%, transparent);
		color: var(--ik-panel-text-muted, #6b7280);
	}
	.iikiti-add-block__name {
		flex: 1;
	}
</style>
