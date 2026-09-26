<script lang="ts">
	interface Props {
		value?: boolean;
		label?: string;
		disabled?: boolean;
		onchange?: (checked: boolean) => void;
	}

	let { value = false, label, disabled = false, onchange }: Props = $props();

	const checked = $derived(!!value);
</script>

<div class="flex items-center gap-3">
	<button
		type="button"
		role="switch"
		aria-checked={String(checked)}
		aria-label={value ? 'Turn off' : 'Turn on'}
		class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 focus:ring-offset-surface"
		class:bg-accent={checked}
		class:bg-surface-hover={!checked}
		class:cursor-not-allowed={disabled}
		{disabled}
		onclick={() => { if (!disabled) onchange?.(!checked); }}
	>
		<span
			class="inline-block h-4 w-4 transform rounded-full bg-surface transition-transform"
			style={checked ? 'margin-left: 1.25rem;' : ''}
		></span>
	</button>
	{#if label}
		<span class="text-sm text-text">{label}</span>
	{/if}
</div>
