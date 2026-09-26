<script lang="ts">
	interface Props {
		message?: string;
		onRetry?: () => void;
	}

	let { message = 'An error occurred.', onRetry }: Props = $props();
</script>

	<div class="p-4 bg-danger-subtle border border-danger-subtle rounded-lg">
		<div class="flex items-start">
			<div class="flex-1">
				<p class="text-sm text-danger">{message}</p>
			</div>
			{#if onRetry}
				<button
					class="ml-3 text-xs text-danger hover:text-danger/80"
					onclick={() => onRetry()}
				>
					Retry
				</button>
			{/if}
		</div>
	</div>
