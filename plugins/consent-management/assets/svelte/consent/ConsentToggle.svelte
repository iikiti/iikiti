<script lang="ts">
	interface Props {
		category: 'analytics' | 'marketing';
		label: string;
		description?: string;
		checked: boolean;
		onchange: (checked: boolean) => void;
	}

	let { category, label, description = '', checked = false, onchange }: Props = $props();

	function toggle() {
		onchange(!checked);
	}
</script>

<div class="flex items-start justify-between gap-3 py-3 border-b border-surface-hover last:border-0">
	<div>
		<h3 class="text-sm font-medium text-text">{label}</h3>
		{#if description}
			<p class="text-xs text-text-muted">{description}</p>
		{/if}
	</div>
	<button
		type="button"
		role="switch"
		aria-checked={String(checked)}
		aria-label={checked ? 'Turn off ' + label : 'Turn on ' + label}
		class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2"
		class:bg-accent={checked}
		class:bg-surface-hover={!checked}
		class:focus:ring-accent={checked}
		onclick={toggle}
	>
		<span
			class="inline-block h-4 w-4 shrink-0 rounded-full bg-surface transition-transform"
			style:transform={checked ? 'translateX(1.25rem)' : 'translateX(0.125rem)'}
		></span>
	</button>
</div>
