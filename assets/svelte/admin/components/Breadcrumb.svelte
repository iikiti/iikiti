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

	<nav class="flex items-center gap-2 text-sm text-text-muted">
		{#each items as crumb, i (i)}
			{#if i > 0}
				<span class="text-border-strong">/</span>
			{/if}
			{#if crumb.path && i < items.length - 1}
				<button
					class="hover:text-text"
					onclick={() => onNavigate?.(crumb.path!)}
				>{crumb.label}</button>
			{:else}
				<span class="text-text">{crumb.label}</span>
			{/if}
		{/each}
	</nav>
