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

	const safeUrl = $derived.by(() => {
		const raw = String(node.content?.url ?? '').trim();
		return /^https?:\/\//i.test(raw) ? raw : '';
	});
</script>

{#if safeUrl}
	<iframe src={safeUrl} title="Embedded content" class="{widgetClass} iikiti-embed__iframe" id={elementId || undefined} allowfullscreen loading="lazy"></iframe>
{:else}
	<span class="{widgetClass} iikiti-video-placeholder" id={elementId || undefined}>Video URL missing.</span>
{/if}
