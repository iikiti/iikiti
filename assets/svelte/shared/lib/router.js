/**
 * Navigation helpers for the admin SPA.
 *
 * The router uses hash-based routing (e.g. /admin/#/users) so that server-side
 * routing always falls back to the SPA. Path state is managed via the
 * `currentPath` $state in App.svelte; this module provides utilities
 * for finding menu items and screens by path.
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

/**
 * Find a screen descriptor by path from the screens manifest.
 *
 * Matches on the pathname only (the leading path before any `?query`), so
 * dynamic-id screens such as `/admin/templates/edit?id=26` resolve against a
 * screen declared with `path: '/admin/templates/edit'`.
 *
 * @param {any[]} screens
 * @param {string} hashPath
 */
export function findScreenByPath(screens, hashPath) {
	const pathname = (hashPath || '/').split('?', 1)[0];

	return screens.find((s) => s.path === pathname) ?? null;
}

/**
 * Extract a single `id` path/query parameter from the current hash path.
 *
 * @param {string} hashPath
 */
export function getRouteId(hashPath) {
	const query = (hashPath || '').split('?', 2)[1] ?? '';
	return new URLSearchParams(query).get('id') ?? null;
}

export function getCurrentPath() {
	if (typeof window === 'undefined') return '/';
	return window.location.hash.slice(1) || '/';
}
