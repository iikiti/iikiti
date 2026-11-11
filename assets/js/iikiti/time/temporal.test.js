import { afterAll, beforeAll, expect, test } from 'bun:test';

let originalDocument;
let formatDateTime;
let formatNumber;
let setSavedLocale;
let setSavedTimeZone;
let userLocale;

beforeAll(async () => {
	originalDocument = globalThis.document;
	globalThis.document = { readyState: 'complete' };
	({ formatDateTime, formatNumber, setSavedLocale, setSavedTimeZone, userLocale } = await import('./temporal.js'));
});

afterAll(() => {
	if (undefined === originalDocument) {
		delete globalThis.document;
	} else {
		globalThis.document = originalDocument;
	}
});

test('uses the saved regional locale for number formatting', () => {
	setSavedLocale('en-CA');

	expect(userLocale()).toBe('en-CA');
	expect(formatNumber(1234.5)).toBe(new Intl.NumberFormat('en-CA').format(1234.5));
});

test('falls back to browser locale when the saved locale is invalid', () => {
	setSavedLocale('en_US');

	expect(userLocale()).not.toBe('en_US');
});

test('uses content locale for formatting only when no user preference exists', () => {
	setSavedLocale(null);

	expect(formatNumber(1234.5, {}, 'fr-CA')).toBe(new Intl.NumberFormat('fr-CA').format(1234.5));
});

test('formats instants with the saved locale and user timezone', async () => {
	setSavedLocale('en-GB');
	setSavedTimeZone('UTC');

	expect(formatDateTime('2026-06-15T13:30:00.000Z', { style: 'short' })).toBe(
		new Intl.DateTimeFormat('en-GB', { dateStyle: 'short', timeStyle: 'short', timeZone: 'UTC' }).format(new Date('2026-06-15T13:30:00.000Z')),
	);
});
