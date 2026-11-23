<script lang="ts">
	import type { Snippet } from 'svelte';

	let {
		node,
		widgetClass,
		elementId = '',
		childBlocks,
	}: {
		node: { type: string };
		widgetClass: string;
		elementId?: string;
		childBlocks?: Snippet;
	} = $props();
</script>

{#if node.type === 'form'}
	<form class="{widgetClass} iikiti-form-preview" id={elementId || undefined} data-block-children onsubmit={(event) => event.preventDefault()}>
		{@render childBlocks?.()}
	</form>
{:else if node.type === 'fieldset'}
	<fieldset class="{widgetClass} iikiti-fieldset-preview" id={elementId || undefined} data-block-children>
		{@render childBlocks?.()}
	</fieldset>
{/if}
