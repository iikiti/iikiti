<script lang="ts">
	import ToggleInput from '$components/ToggleInput.svelte';

	interface Props {
		field: Record<string, unknown>;
		value: unknown;
		onChange: (value: unknown) => void;
	}

	let { field, value, onChange }: Props = $props();
</script>

<ToggleInput
	id={String(field.controlId ?? field.key)}
	aria-labelledby={String(field.labelId ?? `${String(field.key)}-label`)}
	value={Boolean(value)}
	onchange={(v: boolean) => onChange(v)}
/>
