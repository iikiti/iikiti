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

<div class="border-b border-gray-200 dark:border-gray-700">
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

<style>
	.admin-tab {
		@apply py-2 px-1 border-b-2 font-medium text-sm;
	}
	.admin-tab.active {
		@apply border-blue-500 text-blue-600;
	}
	.admin-tab:not(.active) {
		@apply border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300;
	}
	.admin-tab.disabled {
		@apply cursor-not-allowed opacity-50;
	}
</style>
