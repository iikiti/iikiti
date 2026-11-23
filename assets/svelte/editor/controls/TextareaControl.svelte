<script lang="ts">
	import TextareaInput from '$components/TextareaInput.svelte';

	interface Props {
		field: Record<string, unknown>;
		value: unknown;
		onChange: (value: unknown) => void;
	}

	let { field, value, onChange }: Props = $props();
	const controlId = $derived(String(field.controlId ?? field.key));
	const characterGuide = $derived(typeof field.characterGuide === 'number' && field.characterGuide > 0 ? field.characterGuide : 0);
	const characterCount = $derived(Array.from(String(value ?? '')).length);
</script>

<TextareaInput
	id={controlId}
	ariaDescribedBy={characterGuide > 0 ? `${controlId}-character-guide` : undefined}
	value={String(value ?? '')}
	placeholder={String(field.placeholder ?? '')}
	rows={field.rows ? Number(field.rows) : 4}
	oninput={(v) => onChange(v)}
/>
{#if characterGuide > 0}
	<p id={`${controlId}-character-guide`} class="iikiti-settings__hint">
		{characterCount} characters; around {characterGuide} is a useful guide.
	</p>
{/if}
