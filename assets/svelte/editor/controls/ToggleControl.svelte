<script lang="ts">
	import ToggleInput from '$components/ToggleInput.svelte';

	interface Props {
		field: Record<string, unknown>;
		value: unknown;
		onChange: (value: unknown) => void;
	}

	let { field, value, onChange }: Props = $props();
</script>

<ToggleInput value={Boolean(value)} onchange={(v: boolean) => onChange(v)} />
