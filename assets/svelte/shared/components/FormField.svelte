<script lang="ts">
	interface Field {
		key: string;
		label: string;
		type: string;
		required?: boolean;
		readOnlyDisplay?: boolean;
	}

	interface Props {
		field: Field;
		error?: string;
	}

	let { field, error, children }: Props = $props();
</script>

<div>
	{#if field.readOnlyDisplay}
		<p id={`${field.key}-label`} class="block text-sm font-medium text-text mb-1">
			{field.label}
		</p>
	{:else}
		<label id={`${field.key}-label`} for={field.key} class="block text-sm font-medium text-text mb-1">
			{field.label}
			{#if field.required}<span class="text-danger">*</span>{/if}
		</label>
	{/if}
	{@render children?.()}
	{#if error}
		<p class="mt-1 text-xs text-danger">{error}</p>
	{/if}
</div>
