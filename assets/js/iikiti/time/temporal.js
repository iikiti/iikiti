/**
 * Temporal support for the front-end and admin.
 *
 * Native Temporal is used when the browser provides it. Otherwise the
 * polyfill is fetched once, on demand, through the iikiti loader. Consumers
 * await {@link ensureTemporal} before touching `Temporal`.
 */
import { loadIf } from '../loader.js';

export const TEMPORAL_POLYFILL_URL = '/build/vendor/temporal-polyfill/index.umd.js';

/** @returns {boolean} */
export function hasNativeTemporal() {
	return typeof globalThis.Temporal !== 'undefined';
}

/**
 * Resolves once `globalThis.Temporal` is available. Loads the polyfill only
 * when native support is missing.
 *
 * The UMD build publishes its namespace as `globalThis.temporal` and does not
 * define `globalThis.Temporal`, so the reference is read from the namespace.
 *
 * @returns {Promise<typeof Temporal>}
 */
export function ensureTemporal() {
	return loadIf(() => globalThis.Temporal, {
		url: TEMPORAL_POLYFILL_URL,
		read: () => {
			const temporal = globalThis.temporal?.Temporal;
			if (temporal) globalThis.Temporal = temporal;
			return temporal;
		},
	});
}

/** @type {string|null} the user's saved zone, set once at bootstrap */
let savedTimeZone = null;

/**
 * Sets the user's saved IANA zone (from the server). Pass null/'' to clear it,
 * so the browser zone is used. Invalid zones are ignored.
 *
 * @param {string|null|undefined} zone
 */
export function setSavedTimeZone(zone) {
	if (!zone) {
		savedTimeZone = null;
		return;
	}
	try {
		// Throws RangeError for unknown zones.
		new Intl.DateTimeFormat(undefined, { timeZone: zone });
		savedTimeZone = zone;
	} catch {
		savedTimeZone = null;
	}
}

/**
 * The zone used for display: the user's saved zone if set, otherwise the
 * browser's zone, otherwise UTC.
 *
 * @returns {string}
 */
export function userTimeZone() {
	return savedTimeZone ?? (Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');
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
