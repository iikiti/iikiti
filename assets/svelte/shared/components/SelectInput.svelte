<script lang="ts">
	interface Option {
		value: string;
		label: string;
		disabled?: boolean;
	}

	interface Props {
		id?: string;
		name?: string;
		value?: string | number | null | string[] | number[];
		options: Option[];
		placeholder?: string;
		multiple?: boolean;
		disabled?: boolean;
		required?: boolean;
		onchange?: (value: any) => void;
	}

	let {
		id,
		name,
		value,
		options,
		placeholder,
		multiple = false,
		disabled = false,
		required = false,
		onchange,
	}: Props = $props();

	const selectedMultiple = $derived(Array.isArray(value) ? value.map(String) : []);

	function handleChange(e: Event) {
		const target = e.target as HTMLSelectElement;
		if (multiple) {
			onchange?.(Array.from(target.selectedOptions).map((o) => o.value));
		} else {
			onchange?.(target.value);
		}
	}
</script>

{#snippet optionsList()}
	{#if placeholder}
		<option value="" disabled selected={!multiple && (value == null || value === '')}>{placeholder}</option>
	{/if}
	{#each options as option}
		<option
			value={option.value}
			disabled={option.disabled ?? false}
			selected={multiple ? selectedMultiple.includes(option.value) : undefined}
		>{option.label}</option>
	{/each}
{/snippet}

{#if multiple}
	<select
		class="admin-input w-full"
		{id}
		name={name ? `${name}[]` : undefined}
		multiple
		{disabled}
		{required}
		onchange={handleChange}
	>
		{@render optionsList()}
	</select>
{:else}
	<select
		class="admin-input w-full"
		{id}
		{name}
		value={value == null ? '' : String(value)}
		{disabled}
		{required}
		onchange={handleChange}
	>
		{@render optionsList()}
	</select>
{/if}
