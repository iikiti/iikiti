<script lang="ts">
	import type { MenuItem } from '$types';

	interface Props {
		menu: MenuItem[];
		api: any;
		debug?: boolean;
		currentPath?: string;
		onNavigate?: (path: string) => void;
	}

	let { menu, api, debug = false, currentPath = '/', onNavigate, children }: Props = $props();

	const title = $derived(findMenuItem(menu, currentPath)?.label ?? 'Dashboard');

	function findMenuItem(items: MenuItem[], path: string): MenuItem | null {
		for (const item of items) {
			if (item.path === path) return item;
			if (item.children) {
				const found = findMenuItem(item.children, path);
				if (found) return found;
			}
		}
		return null;
	}

	function handleNavigate(path: string) {
		onNavigate?.(path);
	}

	function isActive(item: MenuItem): boolean {
		if (item.path === currentPath) return true;
		return item.children?.some((child) => isActive(child)) ?? false;
	}
</script>

<div class="flex h-screen bg-gray-50 dark:bg-gray-900">
	<aside class="admin-sidebar flex flex-col overflow-y-auto">
		<div class="flex h-16 items-center px-4 border-b border-gray-800">
			<span class="text-xl font-bold text-white">iikiti Admin</span>
		</div>
		<nav class="flex-1 py-2">
			{#each menu as item (item.path)}
				<div class="mb-1">
					<button
						class="admin-nav-item w-full text-left"
						class:active={isActive(item)}
						onclick={() => handleNavigate(item.path)}
					>
						{#if item.icon}
							<span class="icon">{item.icon}</span>
						{/if}
						<span>{item.label}</span>
						{#if item.badge}
							<span class="admin-badge admin-badge-system">{item.badge}</span>
						{/if}
					</button>
					{#if item.children}
						<div class="ml-4 pl-4 border-l border-gray-800">
							{#each item.children as child (child.path)}
								<button
									class="admin-nav-item w-full"
									class:active={isActive(child)}
									onclick={() => handleNavigate(child.path)}
								>
									<span>{child.label}</span>
								</button>
							{/each}
						</div>
					{/if}
				</div>
			{/each}
		</nav>
	</aside>

	<div class="flex-1 flex flex-col overflow-hidden">
		<header class="admin-header px-4 py-3 flex items-center justify-between">
			<h1 class="text-lg font-semibold text-gray-900 dark:text-white">
				{title}
			</h1>
			{#if debug}
				<span class="admin-badge admin-badge-system">DEBUG MODE</span>
			{/if}
		</header>
		<main class="flex-1 overflow-y-auto p-4">
			{@render children?.()}
		</main>
	</div>
</div>
