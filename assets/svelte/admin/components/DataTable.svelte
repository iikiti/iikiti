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
	<div class="text-center py-8 text-gray-500">Loading…</div>
{:else}
	<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
		<table class="admin-table min-w-full divide-y divide-gray-200 dark:divide-gray-700">
			<thead>
				<tr>
					{#each columns as column (column.key)}
						<th class="px-4 py-2 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
							{column.label}
						</th>
					{/each}
				</tr>
			</thead>
			<tbody class="divide-y divide-gray-200 dark:divide-gray-700">
				{#each paginatedItems as row, i (i)}
					<tr
						class="hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer transition-colors"
						onclick={() => onRowClick?.(row)}
					>
						{#each columns as column (column.key)}
							<td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">
								{getCellValue(row, column)}
							</td>
						{/each}
					</tr>
				{/each}
			</tbody>
		</table>
	</div>

	{#if totalItems > 0}
		<div class="mt-4 text-sm text-gray-500 dark:text-gray-400">
			Page {currentPage} of {totalPages}, {totalItems} total
		</div>
	{/if}
{/if}