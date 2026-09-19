/**
 * Navigation helpers for the admin SPA.
 *
 * The router uses hash-based routing (e.g. /admin/#/users) so that server-side
 * routing always falls back to the SPA. Path state is managed via the
 * `currentPath` $state in App.svelte; this module provides utilities
 * for finding menu items by path.
 */

/** @param {any[]} menu @param {string} path */
export function findMenuItem(menu, path) {
	for (const item of menu) {
		if (item.path === path) return item;
		if (item.children) {
			const found = findMenuItem(item.children, path);
			if (found) return found;
		}
	}
	return null;
}

export function getCurrentPath() {
	if (typeof window === 'undefined') return '/';
	return window.location.hash.slice(1) || '/';
}
