/**
 * Creates the small, eagerly available fragment API while keeping its
 * implementation out of the entry chunk until a fragment is actually used.
 * @param {() => Promise<typeof import('./fragments.js')>} [importImplementation]
 */
export function createFragmentsFacade(importImplementation = () => import('./fragments.js')) {
	let implementationPromise;

	function implementation() {
		if (!implementationPromise) {
			implementationPromise = importImplementation().catch((error) => {
				implementationPromise = undefined;
				throw error;
			});
		}
		return implementationPromise;
	}

	return {
		load(provider, options) {
			return implementation().then((api) => api.load(provider, options));
		},
		mount(root) {
			return implementation().then((api) => api.mount(root));
		},
	};
}
