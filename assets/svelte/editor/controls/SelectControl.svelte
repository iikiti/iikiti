<script lang="ts">
	import SelectInput from '$components/SelectInput.svelte';

	interface Props {
		field: Record<string, unknown>;
		value: unknown;
		onChange: (value: unknown) => void;
	}

	let { field, value, onChange }: Props = $props();

	const options = $derived((field.options ?? []) as Array<{ value: string; label: string }>);
</script>

<SelectInput
	value={value == null ? '' : String(value)}
	{options}
	onchange={(v: string) => onChange(v)}
/>
