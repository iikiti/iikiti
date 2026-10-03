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
	oninput={(v) => onChange(field.type === 'number' ? Number(v) : v)}
/>
