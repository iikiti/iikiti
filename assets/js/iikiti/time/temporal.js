/**
 * Temporal support for the front-end and admin.
 *
 * Native Temporal is used when the browser provides it. Otherwise the
 * polyfill is fetched once, on demand, through the iikiti loader. Consumers
 * await {@link ensureTemporal} before touching `Temporal`.
 */
import { loader } from '../loader.js';

export const TEMPORAL_POLYFILL_URL = '/build/vendor/temporal-polyfill/index.umd.js';

let readyPromise = null;

/** @returns {boolean} */
export function hasNativeTemporal() {
	return typeof globalThis.Temporal !== 'undefined';
}

/**
 * Resolves once `globalThis.Temporal` is available. Loads the polyfill only
 * when native support is missing.
 *
 * @returns {Promise<typeof Temporal>}
 */
export function ensureTemporal() {
	if (hasNativeTemporal()) return Promise.resolve(globalThis.Temporal);

	if (!readyPromise) {
		readyPromise = (async () => {
			await loader.loadScript(TEMPORAL_POLYFILL_URL, { strategy: 'complete' });
			// The UMD build publishes its namespace as `globalThis.temporal` and does
			// not define `globalThis.Temporal`, so install the global from it here.
			const namespace = globalThis.temporal;
			if (!namespace?.Temporal) {
				throw new Error('Temporal polyfill loaded but its namespace is unavailable');
			}
			globalThis.Temporal = namespace.Temporal;
			return globalThis.Temporal;
		})();
	}

	return readyPromise;
}

/** The user's IANA time zone, falling back to UTC. */
export function userTimeZone() {
	return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
}

/**
 * Formats an ISO instant for display in the user's time zone.
 *
 * `kind` selects what is shown: `date`, `time`, or `datetime` (date plus
 * optional time). `style` is an Intl-style length (`short`, `medium`, `long`)
 * chosen by the component definer.
 *
 * @param {string} isoInstant  e.g. "2026-10-08T10:02:00Z"
 * @param {{ kind?: 'date'|'time'|'datetime', style?: 'short'|'medium'|'long', timeZone?: string }} [options]
 * @returns {string}
 */
export function formatDateTime(isoInstant, { kind = 'datetime', style = 'medium', timeZone } = {}) {
	const zone = timeZone ?? userTimeZone();
	const instant = Temporal.Instant.from(isoInstant);
	const zoned = instant.toZonedDateTimeISO(zone);

	const dateStyle = style;
	const timeStyle = style === 'long' ? 'long' : 'short';

	const options =
		kind === 'date' ? { dateStyle }
		: kind === 'time' ? { timeStyle }
		: { dateStyle, timeStyle };

	return new Intl.DateTimeFormat(undefined, { ...options, timeZone: zone }).format(
		new Date(zoned.epochMilliseconds),
	);
}
