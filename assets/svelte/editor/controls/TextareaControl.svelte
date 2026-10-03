<script lang="ts">
	import TextareaInput from '$components/TextareaInput.svelte';

	interface Props {
		field: Record<string, unknown>;
		value: unknown;
		onChange: (value: unknown) => void;
	}

	let { field, value, onChange }: Props = $props();
</script>

<TextareaInput
	value={String(value ?? '')}
	placeholder={String(field.placeholder ?? '')}
	rows={field.rows ? Number(field.rows) : 4}
	oninput={(v) => onChange(v)}
/>
