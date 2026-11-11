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
/** @type {string|null} the user's saved BCP 47 locale, set once at bootstrap */
let savedLocale = null;

/**
 * Sets the resolved presentation locale from the server. Invalid values are
 * ignored so the browser's locale remains a safe fallback.
 *
 * @param {string|null|undefined} locale
 */
export function setSavedLocale(locale) {
	if (!locale) {
		savedLocale = null;
		return;
	}
	try {
		new Intl.DateTimeFormat(locale);
		savedLocale = locale;
	} catch {
		savedLocale = null;
	}
}

/** @returns {string|undefined} */
export function userLocale() {
	if (savedLocale) return savedLocale;
	if (typeof navigator === 'undefined') return undefined;
	return navigator.languages?.[0] || navigator.language || undefined;
}

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
 * Formats an ISO instant using the user's regional locale and time zone.
 *
 * `kind` selects what is shown: `date`, `time`, or `datetime` (date plus
 * optional time). `style` is an Intl-style length (`short`, `medium`, `long`)
 * chosen by the component definer.
 *
 * @param {string} isoInstant  e.g. "2026-10-08T10:02:00Z"
 * @param {{ kind?: 'date'|'time'|'datetime', style?: 'short'|'medium'|'long', timeZone?: string }} [options]
 * @param {string|null} [contentLocale] fallback locale when there is no user preference
 * @returns {string}
 */
export function formatDateTime(isoInstant, { kind = 'datetime', style = 'medium', timeZone } = {}, contentLocale = null) {
	const zone = timeZone ?? userTimeZone();
	const instant = Temporal.Instant.from(isoInstant);
	const zoned = instant.toZonedDateTimeISO(zone);

	const dateStyle = style;
	const timeStyle = style === 'long' ? 'long' : 'short';

	const options =
		kind === 'date' ? { dateStyle }
		: kind === 'time' ? { timeStyle }
		: { dateStyle, timeStyle };

	return new Intl.DateTimeFormat(userLocale() ?? contentLocale ?? undefined, { ...options, timeZone: zone }).format(
		new Date(zoned.epochMilliseconds),
	);
}

/** @param {number} value @param {Intl.NumberFormatOptions} [options] @param {string|null} [contentLocale] */
export function formatNumber(value, options = {}, contentLocale = null) {
	return new Intl.NumberFormat(userLocale() ?? contentLocale ?? undefined, options).format(value);
}
