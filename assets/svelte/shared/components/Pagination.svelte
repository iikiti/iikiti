<script lang="ts">
	import Icon from './Icon.svelte';

	interface Props {
		currentPage?: number;
		totalPages?: number;
		totalItems?: number;
		itemsPerPage?: number;
		onPageChange?: (page: number) => void;
	}

	let {
		currentPage = 1,
		totalPages = 1,
		totalItems = 0,
		itemsPerPage = 25,
		onPageChange,
	}: Props = $props();

	const startItem = $derived((currentPage - 1) * itemsPerPage + 1);
	const endItem = $derived(Math.min(currentPage * itemsPerPage, totalItems));

	function gotoPage(page: number) {
		const clamped = Math.max(1, Math.min(page, totalPages));
		if (clamped !== currentPage) {
			onPageChange?.(clamped);
		}
	}
</script>

{#if totalItems > 0}
	<div class="flex items-center justify-between mt-4">
		<div class="text-sm text-text-muted">
			Showing {startItem}-{endItem} of {totalItems} items
		</div>
		{#if totalPages > 1}
			<div class="flex gap-1">
				<button
					class="admin-btn admin-btn-secondary"
					disabled={currentPage <= 1}
					onclick={() => gotoPage(currentPage - 1)}
				>
					<Icon name="chevron-left" size={16} />
					Prev
				</button>
				{#each Array.from({ length: totalPages }, (_, i) => i + 1) as page}
					{#if Math.abs(page - currentPage) <= 2 || page === 1 || page === totalPages}
						<button
							class="admin-btn"
							class:admin-btn-primary={page === currentPage}
							class:admin-btn-secondary={page !== currentPage}
							onclick={() => gotoPage(page)}
						>{page}</button>
					{/if}
				{/each}
				<button
					class="admin-btn admin-btn-secondary"
					disabled={currentPage >= totalPages}
					onclick={() => gotoPage(currentPage + 1)}
				>
					Next
					<Icon name="chevron-right" size={16} />
				</button>
			</div>
		{/if}
	</div>
{/if}
