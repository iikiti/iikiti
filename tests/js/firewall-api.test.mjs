import { test, expect, beforeEach, afterEach } from 'bun:test';
import { ApiClient } from '../../assets/svelte/shared/lib/api.js';

/**
 * Records every fetch call and returns a canned JSON body. Used to assert the
 * exact URL, method, headers, and payload each firewall helper produces.
 */
let calls;
let nextBody;
let originalFetch;

function jsonResponse(body, status = 200) {
	return {
		ok: status >= 200 && status < 300,
		status,
		statusText: 'OK',
		headers: { get: (name) => (name.toLowerCase() === 'content-type' ? 'application/json' : null) },
		json: async () => body,
	};
}

beforeEach(() => {
	calls = [];
	nextBody = {};
	originalFetch = globalThis.fetch;
	globalThis.fetch = async (url, options = {}) => {
		calls.push({ url, options });
		return jsonResponse(nextBody);
	};
});

afterEach(() => {
	globalThis.fetch = originalFetch;
});

const headersOf = (call) => call.options.headers ?? {};

/* ------------------------------------------------------------------ */
/* Scope resolution & header scoping                                  */
/* ------------------------------------------------------------------ */

test('global scope uses admin routes and sends no site host header', async () => {
	const api = new ApiClient('tok', '/api', false, 'iikiti.localhost', 'https');
	nextBody = { 'hydra:member': [] };

	await api.getFirewallIpRules('global', null);

	expect(calls[0].url).toBe('/api/admin/security/firewall/ip-rules');
	expect(headersOf(calls[0])['X-SITE-HOST']).toBeUndefined();
});

test('site scope routes under /site/{siteId} and sends X-SITE-HOST override', async () => {
	const api = new ApiClient('tok', '/api', false, 'iikiti.localhost', 'https');
	nextBody = { 'hydra:member': [] };

	await api.getFirewallIpRules('site', 7);

	expect(calls[0].url).toBe('/api/site/7/security/firewall/ip-rules');
	expect(headersOf(calls[0])['X-SITE-HOST']).toBe('iikiti.localhost');
	expect(headersOf(calls[0])['X-SITE-SCHEME']).toBe('https');
	expect(headersOf(calls[0])['X-AUTH-TOKEN']).toBe('tok');
});

test('site scope without a siteId fails fast instead of calling the API', async () => {
	const api = new ApiClient('tok', '/api');

	await expect(api.getFirewallIpRules('site', null)).rejects.toThrow('site id is required');
	expect(calls.length).toBe(0);
});

test('site scope omits X-SITE-HOST when the client has no site host', async () => {
	const api = new ApiClient('tok', '/api', false, '', 'https');
	nextBody = { member: [] };

	await api.getFirewallBans('site', 3);

	expect(headersOf(calls[0])['X-SITE-HOST']).toBeUndefined();
	expect(calls[0].url).toBe('/api/site/3/security/firewall/bans');
});

test('site-scoped sub-resource ids are URL-encoded', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = {};

	await api.deleteFirewallIpRule('site', 2, 'a/b c');

	expect(calls[0].url).toBe('/api/site/2/security/firewall/ip-rules/a%2Fb%20c');
	expect(calls[0].options.method).toBe('DELETE');
});

test('an explicit per-request siteHost overrides the client default', async () => {
	const api = new ApiClient('tok', '/api', false, 'default.test', 'https');
	nextBody = {};

	await api.request('/site/1/security/firewall/policy', { siteHost: 'other.test' });

	expect(headersOf(calls[0])['X-SITE-HOST']).toBe('other.test');
});

test('siteHost is a routing hint and is not forwarded to fetch', async () => {
	const api = new ApiClient('tok', '/api', false, 'h.test', 'https');
	nextBody = {};

	await api.request('/site/1/security/firewall/policy', { siteHost: 'h.test', method: 'GET' });

	expect('siteHost' in calls[0].options).toBe(false);
	expect(calls[0].options.method).toBe('GET');
});

/* ------------------------------------------------------------------ */
/* Hydra / collection unwrapping                                       */
/* ------------------------------------------------------------------ */

test('unwraps Hydra hydra:member collections into arrays', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = { 'hydra:member': [{ id: 1, cidr: '203.0.113.0/24' }] };

	const rules = await api.getFirewallIpRules('global', null);

	expect(rules).toEqual([{ id: 1, cidr: '203.0.113.0/24' }]);
});

test('unwraps JSON-LD member collections into arrays', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = { member: [{ id: 'b1', cidr: '198.51.100.7' }] };

	const bans = await api.getFirewallBans('global', null);

	expect(bans).toEqual([{ id: 'b1', cidr: '198.51.100.7' }]);
});

test('unwraps bare array responses (non-Hydra Accept)', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = [{ id: 9, name: 'Googlebot' }];

	const catalog = await api.getFirewallBotCatalog();

	expect(catalog).toEqual([{ id: 9, name: 'Googlebot' }]);
});

test('unwraps {items: [...]} collections', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = { items: [{ id: 4, action: 'block' }] };

	const events = await api.getFirewallEvents('global', null);

	expect(events).toEqual([{ id: 4, action: 'block' }]);
});

test('returns an empty array for an unrecognised collection shape', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = { unexpected: true };

	const rules = await api.getFirewallIpRules('global', null);

	expect(rules).toEqual([]);
});

/* ------------------------------------------------------------------ */
/* Endpoint mapping                                                    */
/* ------------------------------------------------------------------ */

test('application policy get and put target the application path', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = { version: 2 };

	await api.getFirewallApplicationPolicy(5);
	await api.updateFirewallApplicationPolicy(5, { version: 2, rateLimits: [] });

	expect(calls[0].url).toBe('/api/admin/applications/5/firewall/policy');
	expect(calls[0].options.method).toBeUndefined();
	expect(calls[1].url).toBe('/api/admin/applications/5/firewall/policy');
	expect(calls[1].options.method).toBe('PUT');
	expect(JSON.parse(calls[1].options.body)).toEqual({ version: 2, rateLimits: [] });
});

test('site protection get and put target the site protection path with host scoping', async () => {
	const api = new ApiClient('tok', '/api', false, 'iikiti.localhost', 'https');
	nextBody = { version: 1 };

	await api.getFirewallSiteProtection(9);
	await api.updateFirewallSiteProtection(9, { version: 1, dos: {} });

	expect(calls[0].url).toBe('/api/site/9/security/firewall/protection');
	expect(calls[1].options.method).toBe('PUT');
	expect(headersOf(calls[1])['X-SITE-HOST']).toBe('iikiti.localhost');
});

test('creating an IP rule POSTs the payload in the requested scope', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = { id: 11 };

	await api.createFirewallIpRule('global', null, { cidr: '10.0.0.0/8', action: 'block' });

	expect(calls[0].url).toBe('/api/admin/security/firewall/ip-rules');
	expect(calls[0].options.method).toBe('POST');
	expect(JSON.parse(calls[0].options.body)).toEqual({ cidr: '10.0.0.0/8', action: 'block' });
});

test('updating an IP rule PUTs to the id-specific path', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = {};

	await api.updateFirewallIpRule('site', 4, 'r9', { action: 'allow' });

	expect(calls[0].url).toBe('/api/site/4/security/firewall/ip-rules/r9');
	expect(calls[0].options.method).toBe('PUT');
});

test('bulk import posts {items} to the global import endpoint only', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = { importedCount: 2, rules: [] };

	const result = await api.importFirewallIpRules([{ cidr: '1.2.3.0/24' }, { cidr: '2001:db8::/32' }]);

	expect(calls[0].url).toBe('/api/admin/security/firewall/ip-rules/import');
	expect(JSON.parse(calls[0].options.body)).toEqual({
		items: [{ cidr: '1.2.3.0/24' }, { cidr: '2001:db8::/32' }],
	});
	expect(result.importedCount).toBe(2);
});

test('manual ban creation posts to the global bans endpoint', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = { id: 'x' };

	await api.createFirewallBan({ cidr: '198.51.100.7', ttlSeconds: 600 });

	expect(calls[0].url).toBe('/api/admin/security/firewall/bans');
	expect(calls[0].options.method).toBe('POST');
});

test('deleting a site ban DELETEs the site-scoped ban path', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = null;

	await api.deleteFirewallBan('site', 6, 'ban-1');

	expect(calls[0].url).toBe('/api/site/6/security/firewall/bans/ban-1');
	expect(calls[0].options.method).toBe('DELETE');
});

test('bot catalog is fetched from the admin catalog endpoint', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = { member: [] };

	await api.getFirewallBotCatalog();

	expect(calls[0].url).toBe('/api/admin/security/firewall/bots/catalog');
});

test('automations CRUD uses the scope-specific automations path', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = {};

	await api.createFirewallAutomation('global', null, { name: 'CI' });
	await api.updateFirewallAutomation('site', 2, 'a1', { name: 'CI2' });
	await api.deleteFirewallAutomation('global', null, 'a1');

	expect(calls[0].url).toBe('/api/admin/security/firewall/automations');
	expect(calls[0].options.method).toBe('POST');
	expect(calls[1].url).toBe('/api/site/2/security/firewall/automations/a1');
	expect(calls[1].options.method).toBe('PUT');
	expect(calls[2].url).toBe('/api/admin/security/firewall/automations/a1');
	expect(calls[2].options.method).toBe('DELETE');
});

test('simulation posts the dry-run payload to the scope simulate endpoint', async () => {
	const api = new ApiClient('tok', '/api', false, 'iikiti.localhost', 'https');
	nextBody = { decision: 'allow' };

	const result = await api.simulateFirewallRequest('site', 1, {
		ip: '203.0.113.5',
		method: 'GET',
		path: '/',
		authenticated: false,
	});

	expect(calls[0].url).toBe('/api/site/1/security/firewall/simulate');
	expect(calls[0].options.method).toBe('POST');
	expect(headersOf(calls[0])['X-SITE-HOST']).toBe('iikiti.localhost');
	expect(result.decision).toBe('allow');
});

test('WAF policy get/put uses the site policy path and wraps rules', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = { rules: [] };

	await api.getFirewallWafPolicy(3);
	await api.updateFirewallWafPolicy(3, [{ mode: 'deny' }]);

	expect(calls[0].url).toBe('/api/site/3/security/firewall/policy');
	expect(calls[1].options.method).toBe('PUT');
	expect(JSON.parse(calls[1].options.body)).toEqual({ rules: [{ mode: 'deny' }] });
});

test('rule packs list unwraps the collection and activation posts the version', async () => {
	const api = new ApiClient('tok', '/api');
	nextBody = { 'hydra:member': [{ key: 'bots', activeVersion: '1' }] };

	const packs = await api.getFirewallRulePacks();
	expect(packs).toEqual([{ key: 'bots', activeVersion: '1' }]);

	nextBody = { success: true };
	await api.activateFirewallRulePack('bots', '2');

	expect(calls[1].url).toBe('/api/admin/security/firewall/packs/bots/activate');
	expect(calls[1].options.method).toBe('POST');
	expect(JSON.parse(calls[1].options.body)).toEqual({ version: '2' });
});

test('non-OK responses surface the status and API error message', async () => {
	const api = new ApiClient('tok', '/api');
	globalThis.fetch = async () => jsonResponse({ title: 'Forbidden for scope' }, 403);

	try {
		await api.getFirewallIpRules('global', null);
		throw new Error('expected rejection');
	} catch (error) {
		expect(error.status).toBe(403);
		expect(error.message).toContain('403');
	}
});
