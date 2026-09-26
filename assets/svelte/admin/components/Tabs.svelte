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

	let active = $state(activeValue ?? tabs.find(t => !t.disabled)?.value ?? tabs[0]?.value);
</script>

<div class="border-b border-border">
	<nav class="-mb-px flex gap-4 overflow-x-auto">
		{#each tabs as tab (tab.value ?? tab.label)}
			<button
				class="admin-tab"
				class:active={active === (tab.value ?? tab.label)}
				class:disabled={tab.disabled}
				onclick={() => { if (!tab.disabled) { active = tab.value ?? tab.label; onSelect?.(active); } }}
			>
				{tab.label}
			</button>
		{/each}
	</nav>
</div>
