/**
 * Pure helpers shared by the Firewall admin tabs.
 *
 * Written as plain JavaScript (with JSDoc types) to match `shared/lib/api.js`;
 * the webpack `ts-loader` rule cannot compile `.ts` modules imported from
 * route components in this build.
 */

/** @typedef {'allow'|'throttle'|'report'|'challenge'|'block'} FirewallPosture */

/** Canonical posture options, in escalation order. */
export const POSTURE_OPTIONS = [
	{ value: 'allow', label: 'Allow' },
	{ value: 'throttle', label: 'Throttle' },
	{ value: 'report', label: 'Report' },
	{ value: 'challenge', label: 'Challenge' },
	{ value: 'block', label: 'Block' },
];

export const BOT_CATEGORY_LABELS = {
	search_engine: 'Search engines',
	ai_training: 'AI training crawlers',
	ai_agent: 'AI user agents',
	monitoring: 'Uptime & monitoring',
	social_preview: 'Social previews',
	malicious_scanner: 'Malicious scanners',
	custom_automation: 'Custom automations',
};

export const BOT_CATEGORIES = Object.keys(BOT_CATEGORY_LABELS);

/**
 * Splits a free-form textarea into trimmed, non-empty lines.
 *
 * @param {string} text
 * @returns {string[]}
 */
export function linesToList(text) {
	return String(text ?? '')
		.split(/\r?\n/)
		.map((line) => line.trim())
		.filter((line) => line.length > 0);
}

/**
 * Joins a list back into one value per line for a textarea.
 *
 * @param {readonly string[]|null|undefined} values
 * @returns {string}
 */
export function listToLines(values) {
	return (values ?? []).join('\n');
}

/**
 * Parses an integer form value, falling back when the input is not numeric.
 *
 * @param {unknown} value
 * @param {number} fallback
 * @returns {number}
 */
export function toInt(value, fallback) {
	const parsed = Number.parseInt(String(value ?? ''), 10);
	return Number.isFinite(parsed) ? parsed : fallback;
}

/**
 * Converts a `datetime-local` value to ISO-8601, or null when empty/invalid.
 *
 * @param {string} value
 * @returns {string|null}
 */
export function localInputToIso(value) {
	if (!value) return null;
	const date = new Date(value);
	return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

/**
 * Formats an ISO timestamp for display. Falls back to the raw string.
 *
 * @param {string|null|undefined} value
 * @returns {string}
 */
export function formatTimestamp(value) {
	if (!value) return '—';
	const date = new Date(value);
	return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

/**
 * True when a record's `expiresAt` is in the past.
 *
 * @param {{ expiresAt: string|null }} record
 * @returns {boolean}
 */
export function isExpired(record) {
	if (!record.expiresAt) return false;
	const time = new Date(record.expiresAt).getTime();
	return Number.isFinite(time) && time <= Date.now();
}

/**
 * Builds a blank rate-limit rule. The id is client-generated and only needs
 * to be unique within one policy.
 *
 * @returns {object}
 */
export function blankRateLimit() {
	return {
		id: `custom:rl-${Date.now().toString(36)}`,
		name: '',
		enabled: true,
		priority: 0,
		inheritance: 'default',
		action: 'report',
		algorithm: 'sliding_window',
		limit: 60,
		interval: 60,
		keyBy: 'ip',
		match: { methods: [], pathPrefix: '', routeGroup: 'custom', authenticated: 'any' },
	};
}

/**
 * Extracts a user-facing message from an API error.
 *
 * @param {unknown} cause
 * @param {string} fallback
 * @returns {string}
 */
export function errorMessage(cause, fallback) {
	return cause instanceof Error ? cause.message : fallback;
}
