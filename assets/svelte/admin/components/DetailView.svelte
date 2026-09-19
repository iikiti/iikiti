<script lang="ts">
	interface Field {
		label: string;
		value: any;
		render?: (value: any) => string;
	}

	interface Props {
		fields: Field[];
		record: Record<string, any>;
	}

	let { fields, record }: Props = $props();
</script>

<div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
	{#each fields as field (field.label)}
		<div>
			<dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{field.label}</dt>
			<dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
				{#if field.render}
					{field.render(record[field.key])}
				{:else if record[field.key] === null || record[field.key] === undefined}
					<span class="text-gray-400">—</span>
				{:else if typeof record[field.key] === 'object'}
					<pre class="text-xs">{JSON.stringify(record[field.key], null, 2)}</pre>
				{:else}
					{String(record[field.key])}
				{/if}
			</dd>
		</div>
	{/each}
</div>
