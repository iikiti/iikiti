<script lang="ts">
	import TextInput from '$components/TextInput.svelte';

	interface Props {
		field: Record<string, unknown>;
		value: unknown;
		onChange: (value: unknown) => void;
	}

	let { field, value, onChange }: Props = $props();

	const type = $derived(field.type === 'url' ? 'url' : field.type === 'number' ? 'number' : 'text');
</script>

<TextInput
	value={String(value ?? '')}
	type={type as 'text' | 'url' | 'number'}
	placeholder={String(field.placeholder ?? '')}
	min={typeof field.min === 'number' ? field.min : undefined}
	max={typeof field.max === 'number' ? field.max : undefined}
	step={typeof field.step === 'number' ? field.step : undefined}
	oninput={(v) => onChange(field.type === 'number' ? (v === '' ? '' : Number(v)) : v)}
/>
