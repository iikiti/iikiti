<script lang="ts">
	import TextInput from '$components/TextInput.svelte';

	interface Props {
		field: Record<string, unknown>;
		value: unknown;
		onChange: (value: unknown) => void;
	}

	let { field, value, onChange }: Props = $props();

	const controlId = $derived(String(field.controlId ?? field.key));
	const type = $derived(field.type === 'url' ? 'url' : field.type === 'number' ? 'number' : 'text');
	const characterGuide = $derived(typeof field.characterGuide === 'number' && field.characterGuide > 0 ? field.characterGuide : 0);
	const characterCount = $derived(Array.from(String(value ?? '')).length);
</script>

<TextInput
	id={controlId}
	ariaDescribedBy={characterGuide > 0 ? `${controlId}-character-guide` : undefined}
	value={String(value ?? '')}
	type={type as 'text' | 'url' | 'number'}
	placeholder={String(field.placeholder ?? '')}
	min={typeof field.min === 'number' ? field.min : undefined}
	max={typeof field.max === 'number' ? field.max : undefined}
	step={typeof field.step === 'number' ? field.step : undefined}
	oninput={(v) => onChange(field.type === 'number' ? (v === '' ? '' : Number(v)) : v)}
/>
{#if characterGuide > 0}
	<p id={`${controlId}-character-guide`} class="iikiti-settings__hint">
		{characterCount} characters; around {characterGuide} is a useful guide.
	</p>
{/if}
