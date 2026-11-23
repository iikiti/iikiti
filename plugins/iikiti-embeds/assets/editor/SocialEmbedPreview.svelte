<script lang="ts">
	let {
		node,
		widgetClass,
		elementId = '',
	}: {
		node: { content?: Record<string, unknown> };
		widgetClass: string;
		elementId?: string;
	} = $props();

	const rawUrl = $derived(String(node.content?.url ?? '').trim());
	const isSafeHttpUrl = $derived(/^https?:\/\//i.test(rawUrl));
</script>

{#if isSafeHttpUrl}
	<a href={rawUrl} class="{widgetClass} iikiti-embed--link-card" id={elementId || undefined} rel="noopener noreferrer">{rawUrl}</a>
{:else if rawUrl}
	<span class="{widgetClass} iikiti-embed--link-card" id={elementId || undefined}>{rawUrl}</span>
{/if}
