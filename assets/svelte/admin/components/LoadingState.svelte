<script lang="ts">
	interface Props {
		label?: string;
	}

	let { label = 'Loading…' }: Props = $props();
</script>

<div class="text-center py-8">
	<div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto mb-3"></div>
	<p class="text-gray-500 dark:text-gray-400">{label}</p>
</div>
