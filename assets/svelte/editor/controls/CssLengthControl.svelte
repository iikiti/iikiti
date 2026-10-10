<script lang="ts">
	import TextInput from '$components/TextInput.svelte';

	interface Props {
		field: Record<string, unknown>;
		value: unknown;
		onChange: (value: unknown) => void;
	}

	let { field, value, onChange }: Props = $props();
</script>

<TextInput
	value={String(value ?? '')}
	placeholder={String(field.placeholder ?? 'e.g. 1rem, 100%')}
	oninput={(next) => onChange(next)}
/>
