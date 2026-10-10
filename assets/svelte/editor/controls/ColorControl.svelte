<script lang="ts">
	import TextInput from '$components/TextInput.svelte';

	interface Props {
		field: Record<string, unknown>;
		value: unknown;
		onChange: (value: unknown) => void;
	}

	let { field, value, onChange }: Props = $props();

	const color = $derived(/^#[\da-f]{3}(?:[\da-f]{3})?$/i.test(String(value ?? '')) ? String(value) : '#000000');
</script>

<div class="iikiti-color-control">
	<input
		type="color"
		value={color}
		aria-label={`${String(field.label ?? 'Color')} picker`}
		oninput={(event) => onChange((event.currentTarget as HTMLInputElement).value)}
	/>
	<TextInput
		value={String(value ?? '')}
		placeholder="#000000"
		oninput={(next) => onChange(next)}
	/>
</div>

<style>
	.iikiti-color-control { display: flex; align-items: center; gap: 8px; }
	.iikiti-color-control input[type='color'] { width: 42px; min-width: 42px; height: 36px; padding: 2px; }
</style>
