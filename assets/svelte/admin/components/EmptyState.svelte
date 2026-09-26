<script lang="ts">
	import Icon from './Icon.svelte';

	interface Props {
		icon?: string;
		title?: string;
		description?: string;
	}

	let { icon = 'inbox', title = 'No results', description }: Props = $props();
</script>

<div class="text-center py-12">
	<div class="mb-3 flex justify-center text-text-subtle">
		<Icon name={icon} size={40} />
	</div>
	<h3 class="text-lg font-medium text-text">{title}</h3>
	{#if description}
		<p class="text-sm text-text-muted mt-1">{description}</p>
	{/if}
</div>
