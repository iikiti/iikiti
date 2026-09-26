<script lang="ts">
	import { onMount } from 'svelte';
	import Icon from './Icon.svelte';
	import type { MenuItem } from '$types';

	interface Props {
		menu: MenuItem[];
		api: any;
		debug?: boolean;
		currentPath?: string;
		currentUser?: string;
		onNavigate?: (path: string) => void;
	}

	let { menu, api, debug = false, currentPath = '/', currentUser = '', onNavigate, children }: Props = $props();

	let dark = $state(false);
	let userMenuOpen = $state(false);
	let sidebarEl: HTMLElement;

	onMount(() => {
		const { iikiti } = window as any;
		dark = document.documentElement.classList.contains('dark') || (iikiti?.theme ? iikiti.theme.get() : false);
		if (iikiti?.components) {
			iikiti.components.create('sidebar', sidebarEl, {
				sticky: ['scroll', 'button'],
				toggleSelector: '#sidebar-toggle',
			});
		}
	});

	function toggleTheme() {
		const iikiti = (window as any).iikiti;
		if (iikiti?.theme) {
			iikiti.theme.toggle();
			dark = !dark;
		} else {
			// Fallback if the front-end theme helper is unavailable.
			const d = document.documentElement;
			if (d.classList.contains('dark')) {
				d.classList.remove('dark');
				dark = false;
			} else {
				d.classList.add('dark');
				dark = true;
			}
		}
	}

	function toggleUserMenu() { userMenuOpen = !userMenuOpen; }

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

	function isActive(item: MenuItem): boolean {
		if (item.path === currentPath) return true;
		return item.children?.some((child) => isActive(child)) ?? false;
	}

	function handleNavigate(path: string) {
		onNavigate?.(path);
	}
</script>

<div class="flex h-screen bg-bg">
	<aside
		bind:this={sidebarEl}
		id="admin-sidebar"
		data-component="sidebar"
		data-sticky="scroll,button"
		data-sticky-toggle="#sidebar-toggle"
		class="admin-sidebar"
	>
		<div class="admin-sidebar-header">
			<span class="label">iikiti Admin</span>
		</div>
		<nav class="flex-1 overflow-y-auto py-2">
			{#each menu as item (item.path)}
				<div class="mb-1">
					<button
						class="admin-nav-item"
						class:active={isActive(item)}
						onclick={() => handleNavigate(item.path)}
					>
						{#if item.icon}<span class="icon"><Icon name={item.icon} /></span>{/if}
						<span data-iikiti-sidebar-label>{item.label}</span>
						{#if item.badge}
							<span class="admin-badge admin-badge-system">{item.badge}</span>
						{/if}
					</button>
					{#if item.children}
						<div class="admin-submenu">
							{#each item.children as child (child.path)}
								<button
									class="admin-nav-item"
									class:active={isActive(child)}
									onclick={() => handleNavigate(child.path)}
								>
									<span data-iikiti-sidebar-label>{child.label}</span>
								</button>
							{/each}
						</div>
					{/if}
				</div>
			{/each}
		</nav>
	</aside>

	<div class="admin-content">
		<header class="admin-header">
			<div class="flex items-center gap-3">
				<button
					id="sidebar-toggle"
					type="button"
					class="admin-btn admin-btn-ghost admin-btn-icon md:hidden"
					aria-label="Toggle sidebar"
					aria-expanded="false"
				>
					<Icon name="menu" size={18} />
				</button>
				<h1 class="text-lg font-semibold text-text">
					{findMenuItem(menu, currentPath)?.label ?? 'Dashboard'}
				</h1>
			</div>
			<div class="flex items-center gap-2">
				{#if debug}
					<span class="admin-badge admin-badge-system">DEBUG MODE</span>
				{/if}
				<button
					type="button"
					class="admin-btn admin-btn-ghost admin-btn-icon"
					aria-label={dark ? 'Switch to light theme' : 'Switch to dark theme'}
					onclick={toggleTheme}
				>
					<Icon name={dark ? 'moon' : 'sun'} size={18} />
				</button>
				<div class="relative">
					<button
						type="button"
						class="admin-btn admin-btn-ghost admin-btn-icon"
						aria-haspopup="menu"
						aria-expanded={String(userMenuOpen)}
						onclick={toggleUserMenu}
					>
						<span class="icon"><Icon name="users" size={16} /></span>
						<span class="hidden sm:inline">{currentUser || 'Admin'}</span>
						<span class="icon"><Icon name="chevron-down" size={14} /></span>
					</button>
					{#if userMenuOpen}
						<div
							class="absolute right-0 mt-2 w-44 rounded-lg border bg-surface text-text shadow-lg"
							role="menu"
						>
							<a href="/logout" class="block px-3 py-2 text-sm hover:bg-surface-hover" role="menuitem">Logout</a>
						</div>
					{/if}
				</div>
			</div>
		</header>
		<main class="flex-1 overflow-y-auto p-4">
			{@render children?.()}
		</main>
	</div>
</div>
