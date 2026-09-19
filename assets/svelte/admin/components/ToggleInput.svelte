<script lang="ts">
	interface Props {
		value?: boolean;
		label?: string;
		disabled?: boolean;
		onchange?: (checked: boolean) => void;
	}

	let { value = false, label, disabled = false, onchange }: Props = $props();
</script>

<div class="flex items-center gap-3">
	<button
		type="button"
		class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500"
		class:bg-blue-600={value}
		class:bg-gray-300={!value}
		class:cursor-not-allowed={disabled}
		{disabled}
		onclick={() => { if (!disabled) onchange?.(!value); }}
	>
		<span
			class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
			style={value ? 'margin-left: 1.25rem;' : ''}
		></span>
	</button>
	{#if label}
		<span class="text-sm text-gray-700 dark:text-gray-300">{label}</span>
	{/if}
</div>
