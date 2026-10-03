/**
 * Minimal shared event bus.
 *
 * Used by the component registry (assets/js/iikiti/components/registry.js)
 * and the bars manager (assets/js/iikiti/chrome/bars.js) so they can
 * broadcast/observe UI events without importing each other.
 */
const listeners = new Map();

export const events = {
	/**
	 * @param {string} name
	 * @param {(payload: unknown) => void} fn
	 * @returns {() => void} unsubscribe
	 */
	on(name, fn) {
		const set = listeners.get(name) ?? new Set();
		set.add(fn);
		listeners.set(name, set);
		return () => set.delete(fn);
	},
	/**
	 * @param {string} name
	 * @param {(payload: unknown) => void} [fn]
	 */
	off(name, fn) {
		const set = listeners.get(name);
		if (!set) return;
		if (fn) set.delete(fn);
		else listeners.delete(name);
	},
	/**
	 * @param {string} name
	 * @param {unknown} [payload]
	 */
	emit(name, payload) {
		const set = listeners.get(name);
		if (!set) return;
		for (const fn of [...set]) fn(payload);
	},
};
