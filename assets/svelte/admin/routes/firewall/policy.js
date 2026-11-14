/**
 * Scope-aware load/save for application and site protection policies.
 *
 * Global scope targets the application policy; site scope targets the site
 * protection policy (which also carries the inherited `effective` view).
 */

/**
 * @param {import('$lib/api').ApiClient} api
 * @param {'global'|'site'} scope
 * @param {string} applicationId
 * @param {string|null} siteId
 * @returns {Promise<object>}
 */
export async function loadProtectionPolicy(api, scope, applicationId, siteId) {
	if (scope === 'site') {
		return api.getFirewallSiteProtection(siteId);
	}
	return api.getFirewallApplicationPolicy(applicationId);
}

/**
 * Persists a full protection policy for the given scope.
 *
 * @param {import('$lib/api').ApiClient} api
 * @param {'global'|'site'} scope
 * @param {string} applicationId
 * @param {string|null} siteId
 * @param {{version: number, rateLimits: object[], dos: object, bots: object}} policy
 * @returns {Promise<object>}
 */
export async function saveProtectionPolicy(api, scope, applicationId, siteId, policy) {
	if (scope === 'site') {
		return api.updateFirewallSiteProtection(siteId, policy);
	}
	return api.updateFirewallApplicationPolicy(applicationId, policy);
}
