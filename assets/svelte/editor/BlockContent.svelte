<script lang="ts">
	import { get } from 'svelte/store';
	import {
		allowedChildTypes,
		blockTypes,
		iconGlyphs,
		iconSvgs,
		openAddBlockDialog,
		searchNode,
	} from './state';
	import type { BlockNode } from './state';
	import Icon from '$components/Icon.svelte';
	import BlockView from './BlockView.svelte';

	let {
		node,
		regionId,
		readonly = false,
		parentId = null,
	}: {
		node: BlockNode;
		regionId: string;
		readonly?: boolean;
		parentId?: string | null;
	} = $props();

	const schema = $derived($blockTypes[node.type] as Record<string, unknown> | undefined);
	const acceptsChildren = $derived(Boolean(schema?.acceptsChildren));
	const childTypes = $derived.by(() => {
		const types = $blockTypes;
		const blockSchema = types?.[node.type];
		if (!blockSchema) return [];
		if (blockSchema.allowedChildTypes == null) {
			return blockSchema.acceptsChildren ? Object.keys(types ?? {}) : [];
		}
		return (blockSchema.allowedChildTypes as string[]) ?? [];
	});

	const inlineTags = ['span', 'em', 'strong', 'b', 'u', 'i', 'small', 'code', 'mark'];

	function headingLevel(): number {
		const level = Number(node.content?.level ?? 2);
		return level >= 2 && level <= 6 ? level : 2;
	}

	function addChild() {
		openAddBlockDialog({
			regionId,
			parentId: node.id,
			position: node.children?.length ?? 0,
			allowedTypes: childTypes,
		});
	}

	function addChildFromSlot(event: MouseEvent) {
		event.stopPropagation();
		event.preventDefault();
		addChild();
	}
</script>

{#if node.type === 'text'}
	{@html node.content?.content ?? ''}
{:else if node.type === 'heading'}
	<svelte:element this={'h' + headingLevel()} class="iikiti-heading-preview" data-block-children>
		{node.content?.text ?? ''}
		{#each node.children ?? [] as child, index (child.id)}
			<BlockView
				node={child}
				{regionId}
				{readonly}
				parentId={node.id}
				index
				isFirst={index === 0}
				isLast={index === (node.children?.length ?? 0) - 1}
				compact
			/>
		{/each}
		{#if acceptsChildren && !readonly}
			<button
				type="button"
				class="iikiti-child-slot iikiti-child-slot--compact"
				data-child-slot={node.id}
				aria-label="Add child to {node.type}"
				title="Add child"
				onclick={addChildFromSlot}
			>
				<Icon name="plus" size={10} />
				<span class="iikiti-child-slot__label">Add child</span>
			</button>
		{/if}
	</svelte:element>
{:else if node.type === 'inline_text'}
	{@const tag = String(node.content?.tag ?? 'plain')}
	{#if tag !== 'plain' && inlineTags.includes(tag)}
		<svelte:element this={tag} class="iikiti-inline-text">
			{node.content?.text ?? ''}
		</svelte:element>
	{:else}
		{node.content?.text ?? ''}
	{/if}
{:else if node.type === 'image'}
	{#if node.content?.source?.url}
		<img src={node.content.source.url} alt={node.content.alt ?? ''} class="iikiti-image" />
	{:else}
		<em class="iikiti-block--placeholder">Image URL missing</em>
	{/if}
{:else if node.type === 'container'}
	<div class="iikiti-container" data-block-children>
		{#each node.children ?? [] as child, index (child.id)}
			<BlockView
				node={child}
				{regionId}
				{readonly}
				parentId={node.id}
				index
				isFirst={index === 0}
				isLast={index === (node.children?.length ?? 0) - 1}
			/>
		{/each}
		{#if acceptsChildren && !readonly}
			<button
				type="button"
				class="iikiti-child-slot"
				data-child-slot={node.id}
				aria-label="Add child to {node.type}"
				title="Add child"
				onclick={addChildFromSlot}
			>
				<Icon name="plus" size={14} />
				<span class="iikiti-child-slot__label">Add child</span>
			</button>
		{/if}
	</div>
{:else if node.type === 'video_embed'}
	<iframe src={node.content?.url} title="Embedded content" class="iikiti-embed__iframe" allowfullscreen loading="lazy"></iframe>
{:else if node.type === 'social_embed'}
	{#if node.content?.url}
		<a href={node.content.url} class="iikiti-embed--link-card">{node.content.url}</a>
	{/if}
{:else if node.type === 'query'}
	<div class="iikiti-query-preview" data-block-children>
		{#each node.children ?? [] as child, index (child.id)}
			<BlockView
				node={child}
				{regionId}
				{readonly}
				parentId={node.id}
				index
				isFirst={index === 0}
				isLast={index === (node.children?.length ?? 0) - 1}
			/>
		{/each}
		{#if (node.children ?? []).length === 0}
			<em class="iikiti-block--placeholder">Query block (preview via API)</em>
		{:else}
			<em class="iikiti-query-preview__hint">Children repeat for every query result</em>
		{/if}
		{#if acceptsChildren && !readonly}
			<button
				type="button"
				class="iikiti-child-slot"
				data-child-slot={node.id}
				aria-label="Add child to {node.type}"
				title="Add child"
				onclick={addChildFromSlot}
			>
				<Icon name="plus" size={14} />
				<span class="iikiti-child-slot__label">Add child</span>
			</button>
		{/if}
	</div>
{:else if node.type === 'dynamic'}
	<em class="iikti-block--placeholder">Dynamic content region</em>
{:else if node.type === 'icon'}
	{@const iconRef = String(node.content?.name ?? '')}
	<span class="iikiti-icon-block" data-block-icon={iconRef}>
		{@html (node.content?.renderer === 'font' ? $iconGlyphs[iconRef] : $iconSvgs[iconRef]) ?? ''}
	</span>
{:else}
	<em class="iikiti-block--placeholder">Unknown block type</em>
{/if}

<style>
	.iikiti-child-slot {
		display: none;
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
		display: none;
		width: auto;
		min-height: 0;
		margin: 0 0 0 4px;
		padding: 0 4px;
		border-width: 1px;
		vertical-align: middle;
	}
	.iikiti-child-slot__label { font-weight: 600; }
	.iikiti-child-slot--compact .iikiti-child-slot__label { display: none; }
	:global(.iikiti-block-preview--hover-active) .iikiti-child-slot { display: flex; }
	:global(.iikiti-block-preview--hover-active) .iikiti-child-slot--compact { display: inline-flex; }
</style>
