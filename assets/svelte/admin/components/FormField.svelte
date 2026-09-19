<script lang="ts">
	interface Field {
		key: string;
		label: string;
		type: string;
		required?: boolean;
	}

	interface Props {
		field: Field;
		error?: string;
	}

	let { field, error, children }: Props = $props();
</script>

<div>
	<label for={field.key} class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
		{field.label}
		{#if field.required}<span class="text-red-500">*</span>{/if}
	</label>
	{@render children?.()}
	{#if error}
		<p class="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>
	{/if}
</div>
