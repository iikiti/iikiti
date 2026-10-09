/**
 * Readiness registry for `window.iikiti` API components.
 *
 * Each component calls `markReady(name)` once it is usable. `whenReady(name)`
 * returns a promise that resolves once that name is ready, including when it
 * became ready before the call (late subscribers still resolve). Names that
 * never become ready stay pending; callers own any timeout.
 *
 * `IIKITI_READY` resolves once every name in `BASE_READY_SET` is ready.
 * `editor.sidebar` is intentionally not in that set: it only exists in editor
 * mode and would otherwise block readiness on every public page.
 */
import { events } from './events.js';

export const IIKITI_READY = 'iikiti:ready';

export const BASE_READY_SET = Object.freeze([
	'components',
	'bars',
	'theme',
	'notifications',
	'plugins',
	'tour',
]);

/** Names that have been marked ready. */
const readyNames = new Set();
/** Resolvers for pending `whenReady` promises, keyed by name. */
const waiters = new Map();

function resolveWaiters(name) {
	const list = waiters.get(name);
	if (!list) return;
	waiters.delete(name);
	for (const resolve of list) resolve();
}

function isReady(name) {
	return readyNames.has(name);
}

/** Idempotent: repeated calls for the same name are no-ops. */
export function markReady(name) {
	if (isReady(name)) return;
	readyNames.add(name);
	resolveWaiters(name);
	events.emit(`ready:${name}`, name);

	if (BASE_READY_SET.includes(name) && BASE_READY_SET.every(isReady)) {
		markReady(IIKITI_READY);
	}
}

/**
 * @param {string} name
 * @returns {Promise<void>}
 */
export function whenReady(name) {
	if (isReady(name)) return Promise.resolve();
	return new Promise((resolve) => {
		const list = waiters.get(name) ?? [];
		list.push(resolve);
		waiters.set(name, list);
	});
}

/** Test-only: resets all readiness state. */
export function __resetReadyForTests() {
	readyNames.clear();
	waiters.clear();
}
