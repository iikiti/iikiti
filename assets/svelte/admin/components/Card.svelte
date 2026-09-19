<script lang="ts">
	interface Props {
		title?: string;
		description?: string;
		footer?: boolean;
		hoverable?: boolean;
	}

	let { title, description, footer = false, hoverable = false, children }: Props = $props();
</script>

<div
	class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700"
	class:hover={hoverable}
>
	{#if title || description}
		<div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
			{#if title}<h3 class="font-medium text-gray-900 dark:text-white">{title}</h3>{/if}
			{#if description}<p class="text-sm text-gray-500 dark:text-gray-400">{description}</p>{/if}
		</div>
	{/if}
	<div class="p-4">
		{@render children?.()}
	</div>
	{#if footer}
		<div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
			{@render children?.({ footer: true })}
		</div>
	{/if}
</div>

<style>
	.hover:hover {
		box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
	}
</style>
