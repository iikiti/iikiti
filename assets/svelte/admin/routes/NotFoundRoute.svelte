<script lang="ts">
	import Icon from '../components/Icon.svelte';

	interface Props {
		currentPath?: string;
	}

	let { currentPath = '/' }: Props = $props();
</script>

<div class="text-center py-12">
	<div class="mb-3 flex justify-center text-text-subtle">
		<Icon name="alert-circle" size={48} />
	</div>
	<h2 class="text-xl font-semibold text-text mb-2">Page Not Found</h2>
	<p class="text-sm text-text-muted">
		The path <code class="px-1 py-0.5 bg-surface-hover rounded">{currentPath}</code> doesn't match any registered screen.
	</p>
</div>
