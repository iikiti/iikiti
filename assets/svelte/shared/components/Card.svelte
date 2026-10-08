<script lang="ts">
	interface Props {
		title?: string;
		description?: string;
		footer?: boolean;
		hoverable?: boolean;
	}

	let { title, description, footer = false, hoverable = false, children }: Props = $props();

	const classes = $derived(`admin-card ${hoverable ? 'hover:shadow-lg cursor-pointer' : ''}`);
</script>

<div class={classes}>
	{#if title || description}
		<div class="admin-card-header">
			{#if title}<h3 class="font-medium text-text">{title}</h3>{/if}
			{#if description}<p class="text-sm text-text-muted">{description}</p>{/if}
		</div>
	{/if}
	<div class="admin-card-body">
		{@render children?.()}
	</div>
	{#if footer}
		<div class="admin-card-footer">
			{@render children?.({ footer: true })}
		</div>
	{/if}
</div>
