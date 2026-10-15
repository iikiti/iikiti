<script lang="ts">
	interface Option {
		value: string;
		label: string;
	}

	interface Props {
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
		name,
		value,
		options,
		placeholder,
		multiple = false,
		disabled = false,
		required = false,
		onchange,
	}: Props = $props();

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
			<option value="" disabled selected>{placeholder}</option>
		{/if}
		{#each options as option}
			<option value={option.value}>{option.label}</option>
		{/each}
	{/snippet}

	{#if multiple}
		<select
			class="admin-input w-full"
			name={name ? `${name}[]` : undefined}
			multiple
			bind:value={value}
			{disabled}
			{required}
			onchange={handleChange}
		>
			{@render optionsList()}
		</select>
	{:else}
		<select
			class="admin-input w-full"
			{name}
			bind:value={value}
			{disabled}
			{required}
			onchange={handleChange}
		>
			{@render optionsList()}
		</select>
	{/if}
