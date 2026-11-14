/**
 * API resource interfaces for the admin UI.
 * Mirrors the PHP {@see \iikiti\CMS\ApiResource\*} DTOs.
 */

/**
 * @typedef {Object} MenuItem
 * @property {string} label
 * @property {string} path
 * @property {string|null} [icon]
 * @property {string|null} [badge]
 * @property {MenuItem[]} [children]
 * @property {number} [priority]
 */

/**
 * @typedef {Object} PagedResult
 * @template T
 * @property {T[]} members
 * @property {number} totalItems
 * @property {number} itemsPerPage
 * @property {number} currentPage
 */

/**
 * @typedef {Object} AuditLogResource
 * @property {number} id
 * @property {number|null} userId
 * @property {string|null} username
 * @property {string|null} summary
 * @property {number|null} parentId
 * @property {string} actorType
 * @property {string} action
 * @property {string} objectType
 * @property {number|null} objectId
 * @property {Object<string,*>|null} beforeState
 * @property {Object<string,*>|null} afterState
 * @property {Object<string,*>} context
 * @property {string|null} ipAddress
 * @property {string|null} requestUri
 * @property {string} createdAt
 * @property {AuditLogResource[]} subEvents
 */

/**
 * @typedef {Object} RoleResource
 * @property {number} id
 * @property {string} name
 * @property {string} value
 * @property {boolean} isDefault
 * @property {boolean} isDeletable
 * @property {boolean} isHidden
 * @property {Object<string,string[]>} defaultPermissions
 * @property {Object<string,string[]>} customPermissions
 * @property {Object<string,string[]>} allPermissions
 */

/**
 * @typedef {Object} UserResource
 * @property {number} id
 * @property {string|null} username
 * @property {string[]|null} emails
 * @property {Object<string,string[]>} globalRoles
 * @property {Object<string,string[]>} siteRoles
 * @property {(number|string)[]|null} groupIds
 * @property {Object<string,*>|null} preferences
 * @property {number|null} creatorId
 * @property {string|null} createdDate
 * @property {string|null} updatedDate
 */

/**
 * @typedef {Object} UserGroupResource
 * @property {number|null} id
 * @property {string} name
 * @property {string} label
 * @property {string|null} description
 * @property {boolean} isSystem
 * @property {boolean} isHidden
 * @property {Object<string,Object<string,string[]>>} permissions
 * @property {number|null} userCount
 */

/**
 * @typedef {Object} SiteGroupResource
 * @property {number|null} id
 * @property {string} name
 * @property {string} label
 * @property {string|null} description
 * @property {boolean} isSystem
 * @property {(string|number)[]} siteIds
 */

/**
 * @typedef {Object} ApplicationResource
 * @property {number} id
 * @property {string} name
 * @property {Object<string,*>|null} configuration
 */

/**
 * @typedef {Object} SiteResource
 * @property {number} id
 * @property {string|null} domain
 * @property {string|null} name
 * @property {Object<string,*>|null} configuration
 */

/**
 * @typedef {Object} PluginResource
 * @property {string} name
 * @property {string} slug
 * @property {string} version
 * @property {boolean} enabled
 * @property {boolean} installed
 * @property {string|null} description
 */

/**
 * @typedef {Object} AdminScreen
 * @property {string} path
 * @property {string} title
 * @property {string} type
 * @property {string|null} [apiPath]
 * @property {string|null} [bundle]
 * @property {string|null} [component]
 * @property {string|null} [permission]
 * @property {string|null} [icon]
 * @property {string|null} [description]
 * @property {Object<string,*>} [config]
 * @property {string|null} [resource]
 */

/**
 * @template T
 * @typedef {Object} Column
 * @property {string} key
 * @property {string} label
 * @property {boolean} [sortable]
 * @property {function(*, T): string} [render]
 * @property {string} [width]
 * @property {('left'|'center'|'right')} [align]
 */

/**
 * @template T
 * @typedef {Object} ActionItem
 * @property {string} label
 * @property {string} icon
 * @property {function(T): void} onClick
 * @property {string|null} [permission]
 */

/** @typedef {'sm'|'md'|'lg'} ComponentSize */
/** @typedef {'primary'|'secondary'|'danger'|'ghost'|'icon'} ComponentVariant */
/** @typedef {'success'|'warning'|'danger'|'info'|'primary'} ComponentStatus */

/**
 * @template T
 * @typedef {Object} ScreenConfig
 * @property {Array<Column<T>>} [columns]
 * @property {Array<*>} [fields]
 * @property {Array<ActionItem<T>>} [actions]
 */

export type { AdminScreen, Column, ActionItem, ScreenConfig, ComponentSize, ComponentVariant, ComponentStatus };

/* ------------------------------------------------------------------ */
/* Firewall (mirrors src/ApiResource/Firewall*Resource.php)           */
/* ------------------------------------------------------------------ */

export type FirewallScope = 'global' | 'site';
export type FirewallIpAction = 'allow' | 'block' | 'report';
export type FirewallInheritance = 'default' | 'required';
export type FirewallPosture = 'allow' | 'throttle' | 'report' | 'challenge' | 'block';
export type FirewallBotCategory =
	| 'search_engine'
	| 'ai_training'
	| 'ai_agent'
	| 'monitoring'
	| 'social_preview'
	| 'malicious_scanner'
	| 'custom_automation';
export type FirewallRateAction = 'throttle' | 'report';
export type FirewallDosAction = 'report' | 'enforce';
export type FirewallRateAlgorithm = 'sliding_window' | 'token_bucket' | 'fixed_window';
export type FirewallAutomationAuthMode = 'cidr' | 'header_secret' | 'hmac' | 'http_signature';

/** Managed allow/block/report IP or CIDR rule (`FirewallIpRuleResource`). */
export interface FirewallIpRule {
	id: number | string | null;
	siteId: number | string | null;
	cidr: string;
	ipVersion: 'v4' | 'v6' | null;
	action: FirewallIpAction;
	inheritance: FirewallInheritance;
	reason: string;
	enabled: boolean;
	expiresAt: string | null;
	priority: number;
	createdAt: string | null;
}

/** Active temporary or manual ban (`FirewallIpBanResource`). */
export interface FirewallIpBan {
	id: number | string | null;
	siteId: number | string | null;
	cidr: string;
	reasonCode: string;
	reasonMessage: string;
	strikes: number;
	expiresAt: string | null;
	createdAt: string | null;
	/** Write-only on create; read-only on collection output. */
	ttlSeconds?: number | null;
}

/** One sliding/token/fixed-window rate-limit rule inside a protection policy. */
export interface FirewallRateLimitRule {
	id: string;
	name: string;
	enabled: boolean;
	priority: number;
	inheritance: FirewallInheritance;
	action: FirewallRateAction;
	algorithm: FirewallRateAlgorithm;
	limit: number;
	interval: number;
	burst?: number;
	keyBy: string;
	match: {
		methods: string[];
		pathPrefix: string;
		routeGroup: string;
		authenticated: 'any' | 'anonymous' | 'authenticated';
	};
}

/** Early structural limits, behavioural guards, and auto-ban escalation (`dos` block). */
export interface FirewallDosConfig {
	required?: boolean;
	enabled: boolean;
	maxHeaderCount: number;
	maxHeaderBytes: number;
	maxUriLength: number;
	maxQueryParams: number;
	maxCookieCount: number;
	maxCookieBytes: number;
	maxBodyBytesByGroup: Record<'auth' | 'forms' | 'api' | 'public', number>;
	errorStormThreshold: number;
	errorStormWindow: number;
	errorStormAction: FirewallDosAction;
	expensiveRouteConcurrencyPerIp: number;
	expensiveRouteConcurrencyPerSite: number;
	concurrencyAction: FirewallDosAction;
	autoBanEnabled: boolean;
	autoBanAction: FirewallDosAction;
	autoBanWindow: number;
	autoBanStrikeThreshold: number;
	autoBanDurations: number[];
}

/** Bot/AI posture, impersonation, honeypot, and challenge settings (`bots` block). */
export interface FirewallBotConfig {
	required?: boolean;
	enabled: boolean;
	fcrdnsEnabled: boolean;
	blockImpersonators: boolean;
	impersonationAction: FirewallDosAction;
	honeypotEnabled: boolean;
	honeypotPaths: string[];
	honeypotAction: FirewallDosAction;
	challengeDifficulty: number;
	challengeTtl: number;
	categoryPostures: Record<FirewallBotCategory, FirewallPosture>;
	botOverrides: Record<string, { posture: FirewallPosture }>;
}

/** Full application or site protection policy (`FirewallApplicationProtectionResource` / `FirewallSiteProtectionResource`). */
export interface FirewallProtectionPolicy {
	applicationId?: number | string | null;
	siteId?: number | string | null;
	version: number;
	rateLimits: FirewallRateLimitRule[];
	dos: FirewallDosConfig | Record<string, unknown>;
	bots: FirewallBotConfig | Record<string, unknown>;
	effective?: { rateLimits: FirewallRateLimitRule[]; dos: FirewallDosConfig; bots: FirewallBotConfig } | null;
}

/** Known bot or AI crawler directory entry (`FirewallBotCatalogResource`). */
export interface FirewallBotCatalogEntry {
	id: string;
	name: string;
	category: FirewallBotCategory | string;
	userAgentPatterns: string[];
	verificationMethod: string;
	description: string;
}

/** Custom trusted automation / webhook / AI-agent credential (`FirewallTrustedAutomationResource`). */
export interface FirewallTrustedAutomation {
	id: number | string | null;
	siteId: number | string | null;
	name: string;
	handle: string;
	category: string;
	authMode: FirewallAutomationAuthMode;
	cidrs: string[];
	headerName: string | null;
	publicKey: string | null;
	/** Write-only; never returned. Send to set or rotate the secret. */
	secret?: string | null;
	/** True when a secret is stored server-side. */
	hasSecret: boolean;
	allowedPaths: string[];
	bypassWaf: boolean;
	rateLimitOverride: Record<string, unknown> | null;
	enabled: boolean;
	priority: number;
}

/** Sanitized firewall security event (`FirewallEventResource`). */
export interface FirewallEventEntry {
	id: number | string | null;
	action: string;
	summary: string;
	ipAddress: string | null;
	requestUri: string | null;
	context: Record<string, unknown>;
	createdAt: string | null;
}

/** Dry-run decision for a hypothetical request (`FirewallSimulateResource`). */
export interface FirewallSimulationResult {
	id: string | null;
	ip: string;
	method: string;
	path: string;
	headers: Record<string, string>;
	userAgent: string;
	authenticated: boolean;
	decision: string;
	ipMatch: Record<string, unknown>;
	botMatch: Record<string, unknown>;
	dosResult: Record<string, unknown>;
	rateLimits: Array<Record<string, unknown>>;
	wafFindings: Array<Record<string, unknown>>;
}

/** Site WAF rule (`FirewallSitePolicyResource.rules[]`). */
export interface FirewallWafRule {
	id?: string;
	name?: string;
	mode: 'deny' | 'report';
	[key: string]: unknown;
}

/** Signed rule pack status (`FirewallRulePackResource`). */
export interface FirewallRulePack {
	key: string;
	name: string;
	activeVersion: string | null;
	checksum: string | null;
	versions: string[];
}

/** Response from `POST /admin/security/firewall/ip-rules/import`. */
export interface FirewallIpRuleImportResult {
	importedCount: number;
	rules: FirewallIpRule[];
}
