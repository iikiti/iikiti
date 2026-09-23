<script lang="ts">
	import { tree, type BlockNode } from './state';
	import BlockView from './BlockView.svelte';

	let { regionId }: { regionId: string } = $props();

	const nodes = $derived(($tree[regionId] ?? []) as BlockNode[]);
</script>

<div class="iikiti-region-content">
	{#each nodes as node (node.id)}
		<BlockView node={node} />
	{/each}
	{#if nodes.length === 0}<em class="iikiti-block--placeholder">Empty region</em>{/if}
</div>

<style>
	.iikiti-region-content { min-height: 2rem; }
</style>
