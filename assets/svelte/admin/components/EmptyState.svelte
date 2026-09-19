<script lang="ts">
	interface Props {
		icon?: string;
		title?: string;
		description?: string;
	}

	let { icon = '📭', title = 'No results', description }: Props = $props();
</script>

<div class="text-center py-12">
	<div class="text-4xl mb-3">{icon}</div>
	<h3 class="text-lg font-medium text-gray-900 dark:text-white">{title}</h3>
	{#if description}
		<p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{description}</p>
	{/if}
</div>
