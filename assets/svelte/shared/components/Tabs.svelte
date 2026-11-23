<script lang="ts">
	interface Tab {
		label: string;
		value?: string | number;
		disabled?: boolean;
	}

	interface Props {
		tabs: Tab[];
		activeValue?: string | number;
		onSelect?: (value: string | number) => void;
	}

	let { tabs, activeValue, onSelect }: Props = $props();

	let selectedValue = $state<string | number | undefined>(undefined);
	const fallbackValue = $derived(
		tabs.find((tab) => !tab.disabled)?.value
		?? tabs.find((tab) => !tab.disabled)?.label
		?? tabs[0]?.value
		?? tabs[0]?.label,
	);
	const active = $derived(activeValue ?? selectedValue ?? fallbackValue);
</script>

<div class="border-b border-border">
	<nav class="-mb-px flex gap-4 overflow-x-auto">
		{#each tabs as tab (tab.value ?? tab.label)}
			{@const tabValue = tab.value ?? tab.label}
			<button
				type="button"
				class="admin-tab"
				class:active={active === tabValue}
				class:disabled={tab.disabled}
				onclick={() => { if (!tab.disabled) { selectedValue = tabValue; onSelect?.(tabValue); } }}
			>
				{tab.label}
			</button>
		{/each}
	</nav>
</div>
