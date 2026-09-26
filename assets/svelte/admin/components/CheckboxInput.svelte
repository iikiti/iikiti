<script lang="ts">
	interface Props {
		value?: boolean;
		label?: string;
		disabled?: boolean;
		onchange?: (checked: boolean) => void;
	}

	let { value = false, label, disabled = false, onchange }: Props = $props();
</script>

<label class="flex items-center gap-2 cursor-pointer">
		<input
			type="checkbox"
			class="h-4 w-4 text-accent rounded"
			checked={value}
			{disabled}
			onchange={(e) => onchange?.(e.currentTarget.checked)}
		/>
		{#if label}
			<span class="text-sm text-text">{label}</span>
		{/if}
</label>
