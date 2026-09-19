<script lang="ts">
	interface Crumb {
		label: string;
		path?: string;
	}

	interface Props {
		items: Crumb[];
		onNavigate?: (path: string) => void;
	}

	let { items, onNavigate }: Props = $props();
</script>

<nav class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
	{#each items as crumb, i (i)}
		{#if i > 0}
			<span class="text-gray-300 dark:text-gray-600">/</span>
		{/if}
		{#if crumb.path && i < items.length - 1}
			<button
				class="hover:text-gray-900 dark:hover:text-white"
				onclick={() => onNavigate?.(crumb.path!)}
			>{crumb.label}</button>
		{:else}
			<span class="text-gray-900 dark:text-white">{crumb.label}</span>
		{/if}
	{/each}
</nav>
