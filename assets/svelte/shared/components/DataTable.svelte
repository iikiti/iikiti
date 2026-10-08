<script lang="ts">
	import type { PagedResult } from '$types';

	interface Column<T> {
		key: keyof T;
		label: string;
		sortable?: boolean;
		render?: (value: any, row: T) => string;
	}

	interface Props<T> {
		data: PagedResult<T> | T[];
		columns: Column<T>[];
		onRowClick?: (row: T) => void;
		loading?: boolean;
	}

	let { data, columns, onRowClick, loading = false }: Props<any> = $props();

	const paginatedItems = $derived(
		Array.isArray(data) ? data : (data as PagedResult<any>)?.members ?? []
	);
	const totalItems = $derived(
		Array.isArray(data) ? data.length : (data as PagedResult<any>)?.totalItems ?? 0
	);
	const itemsPerPage = $derived(
		Array.isArray(data) ? data.length : (data as PagedResult<any>)?.itemsPerPage ?? 25
	);
	const currentPage = $derived(
		Array.isArray(data) ? 1 : (data as PagedResult<any>)?.currentPage ?? 1
	);

	const totalPages = $derived(Math.max(1, Math.ceil(totalItems / itemsPerPage)));

	function getCellValue(row: any, column: Column<any>): string {
		const value = row[column.key];
		if (column.render) {
			return column.render(value, row);
		}
		if (value === null || value === undefined) return '';
		if (typeof value === 'object') return JSON.stringify(value);
		return String(value);
	}
</script>

{#if loading}
	<div class="text-center py-8 text-text-muted">Loading…</div>
{:else}
	<div class="overflow-x-auto rounded-lg border border-border">
		<table class="admin-table min-w-full divide-y divide-border">
			<thead>
				<tr>
					{#each columns as column (column.key)}
						<th>
							{column.label}
						</th>
					{/each}
				</tr>
			</thead>
			<tbody class="divide-y divide-border">
				{#each paginatedItems as row, i (i)}
					<tr
						class="hover:bg-surface-hover cursor-pointer transition-colors"
						onclick={() => onRowClick?.(row)}
					>
						{#each columns as column (column.key)}
							<td>
								{getCellValue(row, column)}
							</td>
						{/each}
					</tr>
				{/each}
			</tbody>
		</table>
	</div>

	{#if totalItems > 0}
		<div class="mt-4 text-sm text-text-muted">
			Page {currentPage} of {totalPages}, {totalItems} total
		</div>
	{/if}
{/if}
