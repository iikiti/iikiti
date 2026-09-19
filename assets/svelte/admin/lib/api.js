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
	 */
	constructor(token, baseUrl = '/api', debug = false) {
		this.token = token;
		this.baseUrl = baseUrl;
		this.debug = debug;
	}

	getHeaders() {
		return {
			'Content-Type': 'application/json',
			'X-AUTH-TOKEN': this.token,
		};
	}

	async request(path, options = {}) {
		const response = await fetch(`${this.baseUrl}${path}`, {
			...options,
			headers: {
				...this.getHeaders(),
				...options.headers,
			},
		});

		if (!response.ok) {
			const body = await response.json().catch(() => ({}));
			const err = new Error(`${response.status}: ${body.title ?? response.statusText}`);
			err.status = response.status;
			err.body = body;
			throw err;
		}

		return response.json();
	}

	parsePaged(data) {
		const members = data['hydra:member'] ?? data.members ?? data;
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

	async getMenu() {
		const data = await this.request('/admin/menu');
		return data.items ?? data;
	}

	async getScreens() {
		const data = await this.request('/admin/screens');
		return data.items ?? data;
	}

	async getResources() {
		const data = await this.request('/admin/resources');
		return data.items ?? data.items ?? data;
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
		return data['hydra:member'] ?? data;
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
}

export class ApiError extends Error {
	constructor(status, statusText, body) {
		super(`${status}: ${body?.title ?? statusText}`);
		this.name = 'ApiError';
		this.status = status;
		this.body = body;
	}
}
