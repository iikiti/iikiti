<script lang="ts">
	interface Props {
		id?: string;
		name?: string;
		value?: boolean;
		label?: string;
		'aria-labelledby'?: string;
		disabled?: boolean;
		onchange?: (checked: boolean) => void;
	}

	let { id, name, value = false, label, 'aria-labelledby': ariaLabelledby, disabled = false, onchange }: Props = $props();

	const checked = $derived(!!value);
</script>

<div class="flex items-center gap-3">
	<input type="checkbox" class="sr-only" {name} value="1" checked={checked} {disabled} tabindex="-1" aria-hidden="true" />
	<button
		{id}
		type="button"
		role="switch"
		aria-checked={String(checked)}
		aria-labelledby={ariaLabelledby}
		aria-label={ariaLabelledby ? undefined : (label ?? (checked ? 'Turn off' : 'Turn on'))}
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
