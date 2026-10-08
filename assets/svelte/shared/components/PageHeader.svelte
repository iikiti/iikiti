<script lang="ts">
	import Icon from './Icon.svelte';

	interface Props {
		title: string;
		description?: string;
		actions?: Array<{ label: string; icon?: string; onclick?: () => void }>;
	}

	let { title, description, actions = [] }: Props = $props();
</script>

<div class="mb-6">
	<div class="flex items-center justify-between">
		<div>
			<h1 class="text-2xl font-bold text-text">{title}</h1>
			{#if description}
				<p class="text-sm text-text-muted mt-1">{description}</p>
			{/if}
		</div>
		{#if actions.length > 0}
			<div class="flex gap-2">
				{#each actions as action, i (i)}
					<button
						class="admin-btn admin-btn-secondary"
						onclick={() => action.onclick?.()}
					>
						{#if action.icon}<Icon name={action.icon} size={16} />{/if}
						{action.label}
					</button>
				{/each}
			</div>
		{/if}
	</div>
</div>
