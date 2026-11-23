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
	import { resolveBlockPreview, sidebarVersion } from './extensions';
	import Icon from '$components/Icon.svelte';
	import IconPreview from './previews/IconPreview.svelte';
	import BlockView from './BlockView.svelte';

	let {
		node,
		regionId,
		readonly = false,
		parentId = null,
		widgetClass,
		elementId = '',
		targetStyle = '',
	}: {
		node: BlockNode;
		regionId: string;
		readonly?: boolean;
		parentId?: string | null;
		widgetClass: string;
		elementId?: string;
		targetStyle?: string;
	} = $props();

	const schema = $derived($blockTypes[node.type] as Record<string, unknown> | undefined);
	const acceptsChildren = $derived(Boolean(schema?.acceptsChildren));
	const childTypes = $derived.by(() => allowedChildTypes(node.type, $blockTypes ?? {}));
	const PluginPreview = $derived.by(() => {
		void $sidebarVersion;
		return resolveBlockPreview(node.type);
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

{#snippet childBlocks()}
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
{/snippet}

{#if PluginPreview}
	{@const PreviewComponent = PluginPreview}
	<PreviewComponent {node} {regionId} {readonly} {parentId} {widgetClass} {elementId} {targetStyle} {childBlocks} iconSvgs={$iconSvgs} iconGlyphs={$iconGlyphs} />
{:else if node.type === 'text'}
	<div class="{widgetClass} iikiti-text" id={elementId || undefined}>{@html node.content?.content ?? ''}</div>
{:else if node.type === 'heading'}
	<svelte:element this={'h' + headingLevel()} class="{widgetClass} iikiti-heading-preview" id={elementId || undefined} data-block-children>
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
		<svelte:element this={tag} class="{widgetClass} iikiti-inline-text" id={elementId || undefined}>
			{node.content?.text ?? ''}
		</svelte:element>
	{:else}
		<span class={widgetClass} id={elementId || undefined}>{node.content?.text ?? ''}</span>
	{/if}
{:else if node.type === 'image'}
	{#if node.content?.source?.url}
		<img src={node.content.source.url} alt={node.content.alt ?? ''} class="{widgetClass} iikiti-image" id={elementId || undefined} style={targetStyle || undefined} />
	{:else}
		<em class="{widgetClass} iikiti-block--placeholder" id={elementId || undefined}>Image URL missing</em>
	{/if}
{:else if node.type === 'icon'}
	<IconPreview {node} {widgetClass} {elementId} {targetStyle} iconSvgs={$iconSvgs} iconGlyphs={$iconGlyphs} />
{:else if node.type === 'container'}
	<div class="{widgetClass} iikiti-container" id={elementId || undefined} data-block-children style={targetStyle || undefined}>
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
{:else if node.type === 'query'}
	<div class="{widgetClass} iikiti-query-preview" id={elementId || undefined} data-block-children style={targetStyle || undefined}>
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
	<em class="{widgetClass} iikti-block--placeholder" id={elementId || undefined}>Dynamic content region</em>
{:else}
	<em class="{widgetClass} iikiti-block--placeholder" id={elementId || undefined}>Unknown block type</em>
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
	.iikiti-form-preview { display: flex; flex-direction: column; gap: 8px; }
	.iikiti-fieldset-preview { min-width: 0; padding: 12px; }
	.iikiti-form-control-preview { display: flex; flex-direction: column; gap: 4px; }
	.iikiti-legend-preview { display: block; }
</style>
