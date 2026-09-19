<script lang="ts">
	interface Props {
		currentPath?: string;
	}

	let { currentPath = '/' }: Props = $props();
</script>

<div class="text-center py-12">
	<div class="text-4xl mb-3">🤔</div>
	<h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Page Not Found</h2>
	<p class="text-sm text-gray-500 dark:text-gray-400">
		The path <code class="px-1 py-0.5 bg-gray-100 dark:bg-gray-800 rounded">{currentPath}</code> doesn't match any registered screen.
	</p>
</div>
