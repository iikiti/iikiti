/**
 * Lightweight API client for the admin SPA.
 *
 * Sends requests with the X-AUTH-TOKEN header injected on page load.
 * Handles Hydra pagination for list endpoints.
 */
export class ApiClient {
	/**
	 * @param {string} token
	 * @param {string} baseUrl
	 * @param {boolean} debug
	 * @param {string} siteHost
	 * @param {string} siteScheme
	 */
	constructor(token, baseUrl = '/api', debug = false, siteHost = '', siteScheme = 'https') {
		this.token = token;
		this.baseUrl = baseUrl;
		this.debug = debug;
		this.siteHost = siteHost;
		this.siteScheme = siteScheme;
	}

	/**
	 * @param {*} body
	 * @param {string|null} [siteHostOverride] `null` keeps the client default;
	 *   an empty string suppresses the site-host headers for that request.
	 */
	getHeaders(body = null, siteHostOverride = null) {
		const headers = { 'X-AUTH-TOKEN': this.token };
		const siteHost = siteHostOverride ?? this.siteHost;
		if (siteHost) {
			headers['X-SITE-HOST'] = siteHost;
			headers['X-SITE-SCHEME'] = this.siteScheme;
		}
		if (typeof FormData === 'undefined' || !(body instanceof FormData)) {
			headers['Content-Type'] = 'application/json';
		}

		return headers;
	}

	async request(path, options = {}) {
		const headers = {
			...this.getHeaders(options.body, options.siteHost ?? null),
			...options.headers,
		};
		// `siteHost` is a client-side routing hint, not a fetch option.
		const { siteHost: _siteHost, ...fetchOptions } = options;
		if (typeof FormData !== 'undefined' && options.body instanceof FormData) {
			delete headers['Content-Type'];
		}

		const response = await fetch(`${this.baseUrl}${path}`, {
			...fetchOptions,
			headers,
		});

		if (!response.ok) {
			const body = await response.json().catch(() => ({}));
			const err = new Error(`${response.status}: ${body.error ?? body.title ?? response.statusText}`);
			err.status = response.status;
			err.body = body;
			throw err;
		}

		if (response.status === 204) {
			return null;
		}

		// Accept any JSON flavour (application/json, application/ld+json,
		// application/hal+json, …) so JSON-LD API responses are parsed too.
		if (response.headers.get('content-type')?.includes('json')) {
			return response.json();
		}

		return response.text();
	}

	/**
	 * Opens an authenticated streaming response (for example, server-sent events).
	 * The token remains in the request header and is never added to the URL.
	 *
	 * @param {string} path
	 * @param {{signal?: AbortSignal, headers?: Record<string,string>, lastEventId?: string|number}} [options]
	 * @returns {Promise<ReadableStreamDefaultReader<Uint8Array>>}
	 */
	async openStream(path, options = {}) {
		const headers = {
			'X-AUTH-TOKEN': this.token,
			Accept: 'text/event-stream',
			...options.headers,
		};
		if (this.siteHost) {
			headers['X-SITE-HOST'] = this.siteHost;
			headers['X-SITE-SCHEME'] = this.siteScheme;
		}
		if (options.lastEventId != null) {
			headers['Last-Event-ID'] = String(options.lastEventId);
		}
		const response = await fetch(`${this.baseUrl}${path}`, {
			method: 'GET',
			headers,
			signal: options.signal,
			cache: 'no-store',
		});
		if (!response.ok) {
			const body = await response.json().catch(() => ({}));
			const error = new Error(`${response.status}: ${body.error ?? response.statusText}`);
			error.status = response.status;
			throw error;
		}
		if (!response.body) {
			throw new Error('Streaming responses are not supported by this browser.');
		}

		return response.body.getReader();
	}

	parsePaged(data) {
		const members = data['hydra:member'] ?? data.members ?? data.member ?? data;
		const total = data['hydra:totalItems'] ?? data.totalItems ?? members.length;
		const perPage = data['hydra:itemsPerPage'] ?? data.itemsPerPage ?? 25;
		const page = data['hydra:view']?.['hydra:page'] ?? 1;

		return {
			members,
			totalItems: total,
			itemsPerPage: perPage,
			currentPage: page,
		};
	}

	/**
	 * Extracts items from an API Platform response, handling both Hydra
	 * collection wrapping (hydra:member) and direct items-array responses.
	 *
	 * @returns {any[]}
	 */
	extractItems(data) {
		if (data && typeof data === 'object' && data['hydra:member']) {
			const member = data['hydra:member'][0];
			// Wrapped resource: { items: [...] } inside the Hydra member,
			// or the member array itself if items are returned directly.
			return member?.items ?? data['hydra:member'];
		}

		if (data && typeof data === 'object' && Array.isArray(data.member)) {
			return data.member;
		}

		if (data && typeof data === 'object' && Array.isArray(data.items)) {
			return data.items;
		}

		return Array.isArray(data) ? data : [];
	}

	async getMenu() {
		const data = await this.request('/admin/menu');
		return this.extractItems(data);
	}

	async getScreens() {
		const data = await this.request('/admin/screens');
		return this.extractItems(data);
	}

	async getResources() {
		const data = await this.request('/admin/resources');
		return this.extractItems(data);
	}

	async getUsers(params) {
		const query = new URLSearchParams(params || {}).toString();
		const path = query ? `/admin/users?${query}` : '/admin/users';
		const data = await this.request(path);
		return this.parsePaged(data);
	}

	async getUser(id) {
		return this.request(`/admin/users/${id}`);
	}

	async getUserGroups(params) {
		const query = new URLSearchParams(params || {}).toString();
		const path = query ? `/admin/user-groups?${query}` : '/admin/user-groups';
		const data = await this.request(path);
		return this.parsePaged(data);
	}

	async getUserGroup(id) {
		return this.request(`/admin/user-groups/${id}`);
	}

	async createUserGroup(data) {
		return this.request('/admin/user-groups', {
			method: 'POST',
			body: JSON.stringify(data),
		});
	}

	async updateUserGroup(id, data) {
		return this.request(`/admin/user-groups/${id}`, {
			method: 'PUT',
			body: JSON.stringify(data),
		});
	}

	async deleteUserGroup(id) {
		await this.request(`/admin/user-groups/${id}`, { method: 'DELETE' });
	}

	async getRoles() {
		const data = await this.request('/admin/roles');
		return data['hydra:member'] ?? data.member ?? data;
	}

	async getRole(id) {
		return this.request(`/admin/roles/${id}`);
	}

	async updateRole(id, data) {
		return this.request(`/admin/roles/${id}`, {
			method: 'PUT',
			body: JSON.stringify(data),
		});
	}

	async getSiteGroups(params) {
		const query = new URLSearchParams(params || {}).toString();
		const path = query ? `/admin/site-groups?${query}` : '/admin/site-groups';
		const data = await this.request(path);
		return this.parsePaged(data);
	}

	async getSiteGroup(id) {
		return this.request(`/admin/site-groups/${id}`);
	}

	async createSiteGroup(data) {
		return this.request('/admin/site-groups', {
			method: 'POST',
			body: JSON.stringify(data),
		});
	}

	async updateSiteGroup(id, data) {
		return this.request(`/admin/site-groups/${id}`, {
			method: 'PUT',
			body: JSON.stringify(data),
		});
	}

	async deleteSiteGroup(id) {
		await this.request(`/admin/site-groups/${id}`, { method: 'DELETE' });
	}

	async getAuditLogs(params) {
		const query = new URLSearchParams(params || {}).toString();
		const path = query ? `/admin/audit-logs?${query}` : '/admin/audit-logs';
		const data = await this.request(path);
		return this.parsePaged(data);
	}

	async getAuditLog(id) {
		return this.request(`/admin/audit-logs/${id}`);
	}

	async getApplications(params) {
		const query = new URLSearchParams(params || {}).toString();
		const path = query ? `/applications?${query}` : '/applications';
		const data = await this.request(path);
		return this.parsePaged(data);
	}

	async getSites(params) {
		const query = new URLSearchParams(params || {}).toString();
		const path = query ? `/sites?${query}` : '/sites';
		const data = await this.request(path);
		return this.parsePaged(data);
	}

	/* ------------------------------------------------------------------ */
	/* Firewall                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Resolves a firewall scope to its request path and optional host override.
	 * Global scope uses admin routes; site scope uses `/site/{siteId}/...` and
	 * sends `X-SITE-HOST` so the site is resolved for the request.
	 *
	 * @param {'global'|'site'} scope
	 * @param {string|number|null} siteId
	 * @returns {{ base: string, siteHost: string|null }}
	 */
	firewallScope(scope, siteId = null) {
		if (scope === 'site') {
			if (siteId === null || siteId === undefined || siteId === '') {
				throw new Error('A site id is required for site-scoped firewall requests.');
			}
			return { base: `/site/${encodeURIComponent(String(siteId))}/security/firewall`, siteHost: this.siteHost || null };
		}

		// Global admin routes are host-independent: an empty override suppresses
		// the client's default X-SITE-HOST so admin calls never resolve a site.
		return { base: '/admin/security/firewall', siteHost: '' };
	}

	/** Builds a firewall request path for a scope plus optional sub-path and id. */
	firewallPath(scope, siteId, subPath = '', id = null) {
		const { base, siteHost } = this.firewallScope(scope, siteId);
		const idPart = id === null || id === undefined ? '' : `/${encodeURIComponent(String(id))}`;

		return { path: `${base}${subPath}${idPart}`, siteHost };
	}

	/**
	 * Unwraps an API Platform collection into a plain array. Handles Hydra
	 * (`hydra:member`), JSON-LD/plain `member`, `{ items: [...] }`, and bare arrays.
	 *
	 * @returns {any[]}
	 */
	unwrapCollection(data) {
		return this.extractItems(data);
	}

	/** Sends a firewall-scoped request, applying the scope's host override. */
	firewallRequest(scope, siteId, subPath, { id = null, method, body, query } = {}) {
		const { path, siteHost } = this.firewallPath(scope, siteId, subPath, id);
		const suffix = query ? `?${new URLSearchParams(query).toString()}` : '';
		return this.request(`${path}${suffix}`, {
			method,
			body: body === undefined ? undefined : JSON.stringify(body),
			siteHost,
		});
	}

	// Application & site protection policy ---------------------------------

	/** @param {string|number} applicationId */
	async getFirewallApplicationPolicy(applicationId) {
		return this.request(`/admin/applications/${encodeURIComponent(String(applicationId))}/firewall/policy`);
	}

	/** @param {string|number} applicationId @param {object} payload */
	async updateFirewallApplicationPolicy(applicationId, payload) {
		return this.request(`/admin/applications/${encodeURIComponent(String(applicationId))}/firewall/policy`, {
			method: 'PUT',
			body: JSON.stringify(payload),
		});
	}

	/** @param {string|number} siteId */
	async getFirewallSiteProtection(siteId) {
		return this.firewallRequest('site', siteId, '/protection', { method: 'GET' });
	}

	/** @param {string|number} siteId @param {object} payload */
	async updateFirewallSiteProtection(siteId, payload) {
		return this.firewallRequest('site', siteId, '/protection', { method: 'PUT', body: payload });
	}

	// IP rules & import -----------------------------------------------------

	/** @returns {Promise<any[]>} */
	async getFirewallIpRules(scope, siteId = null) {
		const data = await this.firewallRequest(scope, siteId, '/ip-rules', { method: 'GET' });
		return this.unwrapCollection(data);
	}

	async createFirewallIpRule(scope, siteId, payload) {
		return this.firewallRequest(scope, siteId, '/ip-rules', { method: 'POST', body: payload });
	}

	async updateFirewallIpRule(scope, siteId, id, payload) {
		return this.firewallRequest(scope, siteId, '/ip-rules', { id, method: 'PUT', body: payload });
	}

	async deleteFirewallIpRule(scope, siteId, id) {
		await this.firewallRequest(scope, siteId, '/ip-rules', { id, method: 'DELETE' });
	}

	/** Global scope only. @param {Array<object>} items */
	async importFirewallIpRules(items) {
		return this.request('/admin/security/firewall/ip-rules/import', {
			method: 'POST',
			body: JSON.stringify({ items }),
		});
	}

	// Active bans ----------------------------------------------------------

	/** @returns {Promise<any[]>} */
	async getFirewallBans(scope, siteId = null) {
		const data = await this.firewallRequest(scope, siteId, '/bans', { method: 'GET' });
		return this.unwrapCollection(data);
	}

	/** Global scope only (manual ban). @param {object} payload */
	async createFirewallBan(payload) {
		return this.request('/admin/security/firewall/bans', {
			method: 'POST',
			body: JSON.stringify(payload),
		});
	}

	async deleteFirewallBan(scope, siteId, id) {
		await this.firewallRequest(scope, siteId, '/bans', { id, method: 'DELETE' });
	}

	// Bot catalog & automations -------------------------------------------

	/** @returns {Promise<any[]>} */
	async getFirewallBotCatalog() {
		const data = await this.request('/admin/security/firewall/bots/catalog');
		return this.unwrapCollection(data);
	}

	/** @returns {Promise<any[]>} */
	async getFirewallAutomations(scope, siteId = null) {
		const data = await this.firewallRequest(scope, siteId, '/automations', { method: 'GET' });
		return this.unwrapCollection(data);
	}

	async createFirewallAutomation(scope, siteId, payload) {
		return this.firewallRequest(scope, siteId, '/automations', { method: 'POST', body: payload });
	}

	async updateFirewallAutomation(scope, siteId, id, payload) {
		return this.firewallRequest(scope, siteId, '/automations', { id, method: 'PUT', body: payload });
	}

	async deleteFirewallAutomation(scope, siteId, id) {
		await this.firewallRequest(scope, siteId, '/automations', { id, method: 'DELETE' });
	}

	// Events & simulation --------------------------------------------------

	/** @returns {Promise<any[]>} */
	async getFirewallEvents(scope, siteId = null) {
		const data = await this.firewallRequest(scope, siteId, '/events', { method: 'GET' });
		return this.unwrapCollection(data);
	}

	async simulateFirewallRequest(scope, siteId, payload) {
		return this.firewallRequest(scope, siteId, '/simulate', { method: 'POST', body: payload });
	}

	// WAF rules & packs ----------------------------------------------------

	/** @param {string|number} siteId */
	async getFirewallWafPolicy(siteId) {
		return this.firewallRequest('site', siteId, '/policy', { method: 'GET' });
	}

	/** @param {string|number} siteId @param {Array<object>} rules */
	async updateFirewallWafPolicy(siteId, rules) {
		return this.firewallRequest('site', siteId, '/policy', { method: 'PUT', body: { rules } });
	}

	/** @returns {Promise<any[]>} */
	async getFirewallRulePacks() {
		const data = await this.request('/admin/security/firewall/packs');
		return this.unwrapCollection(data);
	}

	async activateFirewallRulePack(id, version) {
		return this.request(`/admin/security/firewall/packs/${encodeURIComponent(String(id))}/activate`, {
			method: 'POST',
			body: JSON.stringify({ version }),
		});
	}
}

export class ApiError extends Error {
	constructor(status, statusText, body) {
		super(`${status}: ${body?.title ?? statusText}`);
		this.name = 'ApiError';
		this.status = status;
		this.body = body;
	}
}
