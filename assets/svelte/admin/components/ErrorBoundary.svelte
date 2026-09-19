<script lang="ts">
	interface Props {
		message?: string;
		onRetry?: () => void;
	}

	let { message = 'An error occurred.', onRetry }: Props = $props();
</script>

<div class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
	<div class="flex items-start">
		<div class="flex-1">
			<p class="text-sm text-red-800 dark:text-red-200">{message}</p>
		</div>
		{#if onRetry}
			<button
				class="ml-3 text-xs text-red-600 dark:text-red-300 hover:text-red-800 dark:hover:text-red-200"
				onclick={() => onRetry()}
			>
				Retry
			</button>
		{/if}
	</div>
</div>
