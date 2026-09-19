<script lang="ts">
	interface Option {
		value: string;
		label: string;
	}

	interface Props {
		value?: string | number | null | string[] | number[];
		options: Option[];
		placeholder?: string;
		multiple?: boolean;
		disabled?: boolean;
		required?: boolean;
		onchange?: (value: any) => void;
	}

	let {
		value,
		options,
		placeholder,
		multiple = false,
		disabled = false,
		required = false,
		onchange,
	}: Props = $props();
</script>

<select
	class="admin-input w-full"
	bind:value={value}
	multiple={multiple}
	{disabled}
	{required}
	onchange={(e) => {
		const target = e.target as HTMLSelectElement;
		if (multiple) {
			onchange?.(Array.from(target.selectedOptions).map(o => o.value));
		} else {
			onchange?.(target.value);
		}
	}}
>
	{#if placeholder}
		<option value="" disabled selected>{placeholder}</option>
	{/if}
	{#each options as option}
		<option value={option.value}>{option.label}</option>
	{/each}
</select>
