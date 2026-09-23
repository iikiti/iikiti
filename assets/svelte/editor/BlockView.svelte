<script lang="ts">
	import { onDestroy } from 'svelte';
	import { selected, updateNode, registerBlock } from './state';
	import type { BlockNode } from './state';

	let { node }: { node: BlockNode } = $props();
	let self: HTMLDivElement;

	const isSelected = $derived($selected === node.id);

	$effect(() => {
		if (self) {
			self.dataset.blockId = node.id;
			self.dataset.blockType = node.type;
			registerBlock(node.id, self);
		}
	});

	onDestroy(() => registerBlock(node.id, null));

	function pick(ev: MouseEvent) {
		ev.stopPropagation();
		selected.set(node.id);
	}
</script>

<div
	bind:this={self}
	class:selected={isSelected}
	class="iikiti-block-preview"
	data-block-node
	onclick={pick}
>
	{#if node.type === 'text'}
		{@html node.content?.content ?? ''}
	{:else if node.type === 'heading'}
		<svelte:element this={'h' + Number(node.content?.level ?? 2)}>{node.content?.text ?? ''}</svelte:element>
	{:else if node.type === 'image'}
		{#if node.content?.source?.url}
			<img src={node.content.source.url} alt={node.content.alt ?? ''} class="iikiti-image" />
		{:else}<em class="iikiti-block--placeholder">Image URL missing</em> {/if}
	{:else if node.type === 'container'}
		<div class="iikiti-container" data-block-children>
			{#each node.children ?? [] as child (child.id)}<BlockView node={child} />{/each}
		</div>
	{:else if node.type === 'video_embed'}
		<iframe src={node.content?.url} class="iikiti-embed__iframe" allowfullscreen loading="lazy"></iframe>
	{:else if node.type === 'social_embed'}
		{#if node.content?.url}<a href={node.content.url} class="iikiti-embed--link-card">{node.content.url}</a>{/if}
	{:else if node.type === 'query'}
		<em class="iikiti-block--placeholder">Query block (preview via API)</em>
	{:else if node.type === 'dynamic'}
		<em class="iikti-block--placeholder">Dynamic content region</em>
	{:else}
		<em class="iikiti-block--placeholder">Unknown block type</em>
	{/if}
	{#if isSelected}<div class="iikiti-outline iikiti-outline--selected" aria-hidden="true"></div>{/if}
</div>

<style>
	.iikiti-block-preview[data-block-node] { position: relative; }
	.iikiti-block-preview.selected { outline: 2px solid #3b82f6; outline-offset: 2px; }
	.iikiti-outline--selected { position: absolute; inset: 0; pointer-events: none; border: 2px dashed #3b82f6; border-radius: 3px; }
</style>
