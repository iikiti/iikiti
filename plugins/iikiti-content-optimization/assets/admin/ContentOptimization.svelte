<script lang="ts">
	import { onMount, tick } from 'svelte';
	import { MediaPickerModal, PageHeader } from '@iikiti/ui';
	import type { ApiClient } from '$lib/api';

	interface Props {
		api: ApiClient;
	}

	interface ApplicationOption {
		id: number | string;
		name?: string | null;
	}

	interface SiteOption {
		id: number | string;
		name?: string | null;
		domain?: string | null;
	}

	interface ConnectorSettingSchema {
		type: 'string' | 'boolean' | 'enum';
		label: string;
		required?: boolean;
		maxLength?: number;
		pattern?: string;
		options?: Record<string, string>;
	}

	interface AnalyticsConnector {
		key: string;
		label: string;
		dashboardUrl?: string | null;
		settingSchema: Record<string, ConnectorSettingSchema>;
		cspRequirements: {
			scriptSrc: string[];
			connectSrc: string[];
			imgSrc: string[];
		};
	}

	interface RobotsHeaderRule {
		action: 'set' | 'remove';
		value?: string;
		priority?: number;
		conditional?: boolean;
		required?: boolean;
	}

	interface OptimizationSettings {
		version: 1;
		seo: {
			titleTemplate: string;
			defaultDescription: string;
			defaultSocialImage: string;
			defaultRobots: {
				index: 'index' | 'noindex';
				follow: 'follow' | 'nofollow';
			};
		};
		sitemap: { enabled: boolean };
		crawlers: { aiAccess: 'allow' | 'disallow' };
		analytics: {
			ga4: { enabled: boolean; measurementId: string };
			clarity: { enabled: boolean; projectId: string };
			connectors: Record<string, Record<string, unknown>>;
		};
	}

	type SettingsPath = string;

	type ChoiceOption = { value: string | boolean; label: string };

	const DEFAULT_SETTINGS: OptimizationSettings = {
		version: 1,
		seo: {
			titleTemplate: '{pageTitle} | {siteName}',
			defaultDescription: '',
			defaultSocialImage: '',
			defaultRobots: { index: 'index', follow: 'follow' },
		},
		sitemap: { enabled: true },
		crawlers: { aiAccess: 'allow' },
		analytics: {
			ga4: { enabled: false, measurementId: '' },
			clarity: { enabled: false, projectId: '' },
			connectors: {},
		},
	};

	const TABS = [
		{ label: 'Analytics & Tagging', value: 'analytics' },
		{ label: 'Indexing', value: 'indexing' },
		{ label: 'Social & Defaults', value: 'social' },
	] as const;
	type TabValue = (typeof TABS)[number]['value'];

	const BOOLEAN_OPTIONS: ChoiceOption[] = [
		{ value: true, label: 'Enabled' },
		{ value: false, label: 'Disabled' },
	];
	const INDEX_OPTIONS: ChoiceOption[] = [
		{ value: 'index', label: 'Index pages' },
		{ value: 'noindex', label: 'Do not index pages' },
	];
	const FOLLOW_OPTIONS: ChoiceOption[] = [
		{ value: 'follow', label: 'Follow links' },
		{ value: 'nofollow', label: 'Do not follow links' },
	];
	const AI_ACCESS_OPTIONS: ChoiceOption[] = [
		{ value: 'allow', label: 'Allow AI crawlers' },
		{ value: 'disallow', label: 'Disallow AI crawlers' },
	];

	const SELECT_PATHS: SettingsPath[] = [
		'seo.defaultRobots.index',
		'seo.defaultRobots.follow',
		'sitemap.enabled',
		'crawlers.aiAccess',
		'analytics.ga4.enabled',
		'analytics.clarity.enabled',
	];

	let { api }: Props = $props();
	let scope = $state<'application' | 'site'>('application');
	let activeTab = $state<TabValue>('analytics');
	let applications = $state<ApplicationOption[]>([]);
	let sites = $state<SiteOption[]>([]);
	let applicationId = $state('');
	let siteId = $state('');
	let settings = $state<OptimizationSettings>(makeDefaultSettings());
	let applicationDefaults = $state<OptimizationSettings>(makeDefaultSettings());
	let effectiveSettings = $state<OptimizationSettings>(makeDefaultSettings());
	let siteOverrides = $state<Record<string, unknown>>({});
	let availableConnectors = $state<AnalyticsConnector[]>([]);
	let responseHeaderRules = $state<{ application: RobotsHeaderRule[]; site: RobotsHeaderRule[] }>({ application: [], site: [] });
	let sitePluginEnabled = $state(true);
	let loading = $state(true);
	let saving = $state(false);
	let activationSaving = $state(false);
	let hasLoadedSettings = $state(false);
	let error = $state('');
	let loadError = $state('');
	let canRetry = $state(false);
	let notice = $state('');
	let fieldErrors = $state<Record<string, string>>({});
	let socialImagePickerOpen = $state(false);
	let loadSequence = 0;

	function socialImagePickerHeaders(): Record<string, string> {
		return api.getHeaders(null, scope === 'site' ? selectedSiteHost() : null);
	}

	function openSocialImagePicker(): void {
		if (scope === 'site' && !hasOverride('seo.defaultSocialImage')) {
			beginOverride('seo.defaultSocialImage');
		}
		socialImagePickerOpen = true;
	}

	function handleSocialImageSelect(selected: Record<string, unknown>): void {
		const url = typeof selected.url === 'string' ? selected.url : '';
		updateField('seo.defaultSocialImage', url);
		socialImagePickerOpen = false;
	}

	const hasScopeTarget = $derived(scope === 'application' ? applicationId !== '' : siteId !== '');
	const robotsHeaderRules = $derived([
		...responseHeaderRules.application.map((rule) => ({ ...rule, scope: 'Application' as const })),
		...responseHeaderRules.site.map((rule) => ({ ...rule, scope: 'Site' as const })),
	]);
	const hasPotentialRobotsHeaderConflict = $derived(
		effectiveValue('seo.defaultRobots.index') === 'index' &&
		robotsHeaderRules.some((rule) => rule.action === 'set' && /\bnoindex\b/i.test(rule.value ?? '')),
	);

	function makeDefaultSettings(): OptimizationSettings {
		return JSON.parse(JSON.stringify(DEFAULT_SETTINGS)) as OptimizationSettings;
	}

	function normalizeSettings(value: unknown): OptimizationSettings {
		const source = value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
		const seo = source.seo && typeof source.seo === 'object' ? (source.seo as Record<string, unknown>) : {};
		const robots = seo.defaultRobots && typeof seo.defaultRobots === 'object'
			? (seo.defaultRobots as Record<string, unknown>)
			: {};
		const sitemap = source.sitemap && typeof source.sitemap === 'object'
			? (source.sitemap as Record<string, unknown>)
			: {};
		const crawlers = source.crawlers && typeof source.crawlers === 'object'
			? (source.crawlers as Record<string, unknown>)
			: {};
		const analytics = source.analytics && typeof source.analytics === 'object'
			? (source.analytics as Record<string, unknown>)
			: {};
		const ga4 = analytics.ga4 && typeof analytics.ga4 === 'object'
			? (analytics.ga4 as Record<string, unknown>)
			: {};
		const clarity = analytics.clarity && typeof analytics.clarity === 'object'
			? (analytics.clarity as Record<string, unknown>)
			: {};
		const connectors = analytics.connectors && typeof analytics.connectors === 'object' && !Array.isArray(analytics.connectors)
			? (analytics.connectors as Record<string, Record<string, unknown>>)
			: {};

		return {
			...DEFAULT_SETTINGS,
			version: 1,
			seo: {
				...DEFAULT_SETTINGS.seo,
				...seo,
				defaultRobots: { ...DEFAULT_SETTINGS.seo.defaultRobots, ...robots },
			},
			sitemap: { ...DEFAULT_SETTINGS.sitemap, ...sitemap },
			crawlers: { ...DEFAULT_SETTINGS.crawlers, ...crawlers },
			analytics: {
				...DEFAULT_SETTINGS.analytics,
				...analytics,
				ga4: { ...DEFAULT_SETTINGS.analytics.ga4, ...ga4 },
				clarity: { ...DEFAULT_SETTINGS.analytics.clarity, ...clarity },
				connectors,
			},
		} as OptimizationSettings;
	}

	const BLOCKED_PATH_KEYS = new Set(['__proto__', 'constructor', 'prototype']);

	function normalizeOverrides(value: unknown): Record<string, unknown> {
		if (!value || typeof value !== 'object' || Array.isArray(value)) return {};
		const overrides = JSON.parse(JSON.stringify(value)) as Record<string, unknown>;
		const analytics = overrides.analytics;
		if (analytics && typeof analytics === 'object' && !Array.isArray(analytics)) {
			const analyticsSettings = analytics as Record<string, unknown>;
			if (Object.prototype.hasOwnProperty.call(analyticsSettings, 'connectors')) {
				const connectors = analyticsSettings.connectors;
				if (!connectors || typeof connectors !== 'object' || Array.isArray(connectors) || Object.keys(connectors).length === 0) {
					delete analyticsSettings.connectors;
				}
			}
		}
		return overrides;
	}

	function normalizeRobotsHeaderRules(value: unknown): { application: RobotsHeaderRule[]; site: RobotsHeaderRule[] } {
		if (!value || typeof value !== 'object') return { application: [], site: [] };
		const rules = value as Record<string, unknown>;
		const normalize = (list: unknown): RobotsHeaderRule[] => {
			if (!Array.isArray(list)) return [];
			return list.filter((rule): rule is RobotsHeaderRule => {
				if (!rule || typeof rule !== 'object') return false;
				const action = (rule as Partial<RobotsHeaderRule>).action;
				return action === 'set' || action === 'remove';
			});
		};
		return {
			application: normalize(rules.application),
			site: normalize(rules.site),
		};
	}

	function readPath(source: unknown, path: SettingsPath): unknown {
		let value = source;
		for (const part of path.split('.')) {
			if (BLOCKED_PATH_KEYS.has(part)) return undefined;
			if (!value || typeof value !== 'object' || !Object.prototype.hasOwnProperty.call(value, part)) return undefined;
			value = (value as Record<string, unknown>)[part];
		}
		return value;
	}

	function hasPath(source: unknown, path: SettingsPath): boolean {
		let value = source;
		for (const part of path.split('.')) {
			if (BLOCKED_PATH_KEYS.has(part)) return false;
			if (!value || typeof value !== 'object' || !Object.prototype.hasOwnProperty.call(value, part)) return false;
			value = (value as Record<string, unknown>)[part];
		}
		return true;
	}

	function writePath(
		source: Record<string, unknown>,
		parts: string[],
		newValue: unknown,
	): Record<string, unknown> {
		const [key, ...remaining] = parts;
		if (!key || BLOCKED_PATH_KEYS.has(key)) return source;
		if (remaining.length === 0) return { ...source, [key]: newValue };
		const existing = Object.prototype.hasOwnProperty.call(source, key) ? source[key] : undefined;
		const child = existing && typeof existing === 'object' && !Array.isArray(existing)
			? (existing as Record<string, unknown>)
			: {};
		return { ...source, [key]: writePath(child, remaining, newValue) };
	}

	function deletePath(source: Record<string, unknown>, parts: string[]): Record<string, unknown> {
		const [key, ...remaining] = parts;
		if (!key || BLOCKED_PATH_KEYS.has(key) || !Object.prototype.hasOwnProperty.call(source, key)) return source;
		const next = { ...source };
		if (remaining.length === 0) {
			delete next[key];
			return next;
		}
		const child = next[key];
		if (!child || typeof child !== 'object') return next;
		const updatedChild = deletePath(child as Record<string, unknown>, remaining);
		if (Object.keys(updatedChild).length === 0) delete next[key];
		else next[key] = updatedChild;
		return next;
	}

	function fieldId(path: SettingsPath): string {
		return `content-optimization-${path.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
	}

	function fieldDescription(path: SettingsPath, hint: string): string | undefined {
		const ids = [];
		if (hint) ids.push(`${fieldId(path)}-hint`);
		if (fieldErrors[path]) ids.push(`${fieldId(path)}-error`);
		return ids.length ? ids.join(' ') : undefined;
	}

	function hasOverride(path: SettingsPath): boolean {
		return hasPath(siteOverrides, path);
	}

	function fieldValue(path: SettingsPath): unknown {
		if (scope === 'site') {
			return hasOverride(path) ? readPath(siteOverrides, path) : readPath(effectiveSettings, path);
		}
		return readPath(settings, path);
	}

	function effectiveValue(path: SettingsPath): unknown {
		return scope === 'site' ? fieldValue(path) : readPath(settings, path);
	}

	function displayValue(value: unknown, options?: ChoiceOption[] | Record<string, string>): string {
		if (value === undefined || value === null) return 'Not set';
		if (value === '') return 'Empty value';
		if (Array.isArray(options)) {
			const match = options.find((option) => option.value === value || String(option.value) === String(value));
			if (match) return match.label;
		} else if (options && typeof options === 'object' && Object.prototype.hasOwnProperty.call(options, String(value))) {
			return options[String(value)];
		}
		if (value === true) return 'Enabled';
		if (value === false) return 'Disabled';
		return String(value);
	}

	function isSafeHttpsUrl(value: string | null | undefined): boolean {
		return typeof value === 'string' && /^https:\/\//i.test(value.trim());
	}

	function updateField(path: SettingsPath, value: unknown): void {
		fieldErrors = { ...fieldErrors, [path]: '' };
		error = '';
		canRetry = false;
		notice = '';
		if (scope === 'site') {
			siteOverrides = writePath(siteOverrides, path.split('.'), value);
		} else {
			settings = ensureApplicationConnectorShape(path, settings);
			settings = writePath(settings as unknown as Record<string, unknown>, path.split('.'), value) as unknown as OptimizationSettings;
		}
	}

	function ensureApplicationConnectorShape(path: SettingsPath, current: OptimizationSettings): OptimizationSettings {
		const parts = path.split('.');
		if (parts[0] !== 'analytics' || parts[1] !== 'connectors' || parts.length < 5) return current;
		const connectorPath = parts.slice(0, 4).join('.');
		let next = current as unknown as Record<string, unknown>;
		const enabledPath = `${connectorPath}.enabled`;
		if (path !== enabledPath && !hasPath(next, enabledPath)) {
			next = writePath(next, enabledPath.split('.'), false);
		}
		const settingsPath = `${connectorPath}.settings`;
		if (!hasPath(next, settingsPath)) {
			next = writePath(next, settingsPath.split('.'), {});
		}

		return next as unknown as OptimizationSettings;
	}

	function beginOverride(path: SettingsPath, emptyValue: unknown = SELECT_PATHS.includes(path) ? null : ''): void {
		siteOverrides = writePath(siteOverrides, path.split('.'), emptyValue);
		fieldErrors = { ...fieldErrors, [path]: '' };
		error = '';
		canRetry = false;
		notice = '';
	}

	function resetOverride(path: SettingsPath): void {
		siteOverrides = deletePath(siteOverrides, path.split('.'));
		fieldErrors = { ...fieldErrors, [path]: '' };
		error = '';
		canRetry = false;
		notice = '';
	}

	function updateChoice(path: SettingsPath, value: string): void {
		const parsedValue = SELECT_PATHS.includes(path) && path.endsWith('.enabled')
			? value === 'true'
			: value;
		updateField(path, value === '' ? null : parsedValue);
	}

	function isRequiredPath(path: SettingsPath): boolean {
		if (path === 'analytics.ga4.measurementId') return effectiveValue('analytics.ga4.enabled') === true;
		if (path === 'analytics.clarity.projectId') return effectiveValue('analytics.clarity.enabled') === true;
		return false;
	}

	function connectorParts(connector: AnalyticsConnector): [string, string] {
		const [pluginSlug = '', connectorId = ''] = connector.key.split('.', 2);
		return [pluginSlug, connectorId];
	}

	function connectorConfigPath(connector: AnalyticsConnector): string {
		const [pluginSlug, connectorId] = connectorParts(connector);
		return `analytics.connectors.${pluginSlug}.${connectorId}`;
	}

	function connectorHasConfiguration(connector: AnalyticsConnector): boolean {
		const path = connectorConfigPath(connector);
		return scope === 'site'
			? hasPath(siteOverrides, path)
			: hasPath(settings, path);
	}

	function removeConnectorConfiguration(connector: AnalyticsConnector): void {
		const path = connectorConfigPath(connector);
		if (scope === 'site') {
			siteOverrides = deletePath(siteOverrides, path.split('.'));
		} else {
			settings = deletePath(settings as unknown as Record<string, unknown>, path.split('.')) as unknown as OptimizationSettings;
		}
		const prefix = `${path}.`;
		fieldErrors = Object.fromEntries(
			Object.entries(fieldErrors).filter(([key]) => key !== path && !key.startsWith(prefix)),
		);
		error = '';
		canRetry = false;
		notice = '';
	}

	function connectorEnabledPath(connector: AnalyticsConnector): string {
		return `${connectorConfigPath(connector)}.enabled`;
	}

	function connectorSettingPath(connector: AnalyticsConnector, field: string): string {
		return `${connectorConfigPath(connector)}.settings.${field}`;
	}

	function connectorValue(path: string, fallback?: unknown): unknown {
		const value = effectiveValue(path);
		return value === undefined ? fallback : value;
	}

	function updateConnectorChoice(path: string, value: string, type: 'boolean' | 'enum'): void {
		updateField(path, value === '' ? null : type === 'boolean' ? value === 'true' : value);
	}

	function requiredConnectorSetting(definition: ConnectorSettingSchema): boolean {
		return definition.required !== false;
	}

	function connectorCspDirectives(connector: AnalyticsConnector): Array<[string, string[]]> {
		return [
			['script-src', connector.cspRequirements.scriptSrc],
			['connect-src', connector.cspRequirements.connectSrc],
			['img-src', connector.cspRequirements.imgSrc],
		];
	}

	function isCurrentSite(siteList: SiteOption[]): string {
		const host = (api as ApiClient & { siteHost?: string }).siteHost ?? '';
		const match = host ? siteList.find((site) => (site.domain ?? '') === host) : undefined;
		return String((match ?? siteList[0])?.id ?? '');
	}

	function selectedSiteHost(): string {
		const host = sites.find((site) => String(site.id) === siteId)?.domain;
		return host ?? (api as ApiClient & { siteHost?: string }).siteHost ?? '';
	}

	async function fetchAllPages<T>(fetchPage: (page: number) => Promise<{ members?: unknown[]; totalItems?: number }>): Promise<T[]> {
		const collected: T[] = [];
		const maxPages = 20;
		for (let page = 1; page <= maxPages; page++) {
			const response = await fetchPage(page);
			const members = (response.members ?? []) as T[];
			collected.push(...members);
			const totalItems = Number(response.totalItems ?? collected.length);
			if (members.length === 0 || collected.length >= totalItems) break;
		}
		return collected;
	}

	function applyConfigurationResponse(response: Record<string, unknown>): void {
		if (scope === 'application') {
			settings = normalizeSettings(response?.settings ?? response);
			applicationDefaults = settings;
		} else {
			applicationDefaults = normalizeSettings(response?.applicationDefaults ?? response?.effective ?? DEFAULT_SETTINGS);
			effectiveSettings = normalizeSettings(response?.effective ?? DEFAULT_SETTINGS);
			sitePluginEnabled = response?.pluginEnabled !== false;
			siteOverrides = normalizeOverrides(response?.overrides);
		}
		responseHeaderRules = normalizeRobotsHeaderRules(response?.responseHeaderRules);
		availableConnectors = Array.isArray(response?.availableConnectors)
			? response.availableConnectors as AnalyticsConnector[]
			: [];
		hasLoadedSettings = true;
	}

	async function loadScopes(): Promise<void> {
		loading = true;
		error = '';
		loadError = '';
		canRetry = false;
		try {
			sites = await fetchAllPages<SiteOption>((page) => api.getSites({ page, itemsPerPage: 100 }));
			try {
				applications = await fetchAllPages<ApplicationOption>((page) => api.getApplications({ page, itemsPerPage: 100 }));
			} catch {
				// Site managers can configure site overrides without global access.
				applications = [];
			}
			if (applications.length === 0) scope = 'site';
			if (!applicationId && applications.length) applicationId = String(applications[0].id);
			if (!siteId && sites.length) siteId = isCurrentSite(sites);
			await loadConfiguration(false);
		} catch (cause) {
			loadError = cause instanceof Error ? cause.message : 'Unable to load applications and sites.';
			canRetry = true;
			loading = false;
		}
	}

	async function loadConfiguration(clearMessages = true, preserveDataOnFailure = false): Promise<boolean> {
		const sequence = ++loadSequence;
		if (clearMessages) {
			error = '';
			loadError = '';
			canRetry = false;
			notice = '';
			fieldErrors = {};
			hasLoadedSettings = false;
		}
		if (!hasScopeTarget) {
			loading = false;
			return false;
		}

		loading = true;
		const resourcePath = scope === 'application'
			? `/admin/applications/${encodeURIComponent(applicationId)}/content-optimization/settings`
			: `/site/${encodeURIComponent(siteId)}/content-optimization/settings`;
		try {
			const response = await api.request(resourcePath, scope === 'site' ? { siteHost: selectedSiteHost() } : {});
			if (sequence !== loadSequence) return false;
			applyConfigurationResponse((response ?? {}) as Record<string, unknown>);
			return true;
		} catch (cause) {
			if (sequence === loadSequence) {
				const status = cause && typeof cause === 'object' && 'status' in cause
					? Number((cause as { status: unknown }).status)
					: 0;
				if (scope === 'application' && status === 403 && siteId) {
					applications = [];
					scope = 'site';
					void loadConfiguration(false);
					return false;
				}
				const message = cause instanceof Error ? cause.message : 'Unable to load content optimization settings.';
				if (preserveDataOnFailure && hasLoadedSettings) {
					error = '';
					canRetry = false;
				} else {
					loadError = message;
					canRetry = true;
				}
			}
			return false;
		} finally {
			if (sequence === loadSequence) loading = false;
		}
	}

	async function updateSiteActivation(event: Event): Promise<void> {
		if (scope !== 'site' || !siteId) return;
		const input = event.currentTarget;
		if (!(input instanceof HTMLInputElement)) return;

		const enabled = input.checked;
		activationSaving = true;
		error = '';
		notice = '';
		try {
			const response = await api.request(`/site/${encodeURIComponent(siteId)}/content-optimization/activation`, {
				method: 'PUT',
				body: JSON.stringify({ enabled }),
				siteHost: selectedSiteHost(),
			});
			sitePluginEnabled = response?.enabled === true;
			notice = sitePluginEnabled
				? 'Content Optimization is active on this site.'
				: 'Content Optimization is disabled on this site. Public metadata and analytics will not run until it is re-enabled.';
		} catch (cause) {
			input.checked = sitePluginEnabled;
			error = cause instanceof Error ? cause.message : 'Unable to change site activation.';
			canRetry = false;
		} finally {
			activationSaving = false;
		}
	}

	function onScopeChange(): void {
		fieldErrors = {};
		void loadConfiguration();
	}

	function selectScope(nextScope: 'application' | 'site'): void {
		if (nextScope === 'application' && applications.length === 0) return;
		if (scope === nextScope) return;
		scope = nextScope;
		onScopeChange();
	}

	function onTabKeydown(event: KeyboardEvent, index: number): void {
		let nextIndex: number | null = null;
		if (event.key === 'ArrowRight') nextIndex = (index + 1) % TABS.length;
		else if (event.key === 'ArrowLeft') nextIndex = (index - 1 + TABS.length) % TABS.length;
		else if (event.key === 'Home') nextIndex = 0;
		else if (event.key === 'End') nextIndex = TABS.length - 1;
		if (nextIndex === null) return;
		event.preventDefault();
		activeTab = TABS[nextIndex].value;
		event.currentTarget instanceof HTMLElement &&
			event.currentTarget.parentElement?.querySelectorAll<HTMLButtonElement>('[role="tab"]')[nextIndex]?.focus();
	}

	function validate(): boolean {
		const nextErrors: Record<string, string> = {};
		if (scope === 'site') {
			for (const path of SELECT_PATHS) {
				if (hasOverride(path) && fieldValue(path) === null) {
					nextErrors[path] = 'Choose a value before saving this override.';
				}
			}
		}
		if (effectiveValue('analytics.ga4.enabled') === true && !String(effectiveValue('analytics.ga4.measurementId') ?? '').trim()) {
			nextErrors['analytics.ga4.measurementId'] = 'Enter a measurement ID when GA4 is enabled.';
		}
		if (effectiveValue('analytics.clarity.enabled') === true && !String(effectiveValue('analytics.clarity.projectId') ?? '').trim()) {
			nextErrors['analytics.clarity.projectId'] = 'Enter a project ID when Clarity is enabled.';
		}
		for (const connector of availableConnectors) {
			const enabledPath = connectorEnabledPath(connector);
			const enabled = connectorValue(enabledPath, false) === true;
			if (scope === 'site' && hasOverride(enabledPath) && fieldValue(enabledPath) === null) {
				nextErrors[enabledPath] = 'Choose whether this connector is enabled at this site.';
			}

		const needsRequiredSettings = enabled;
			for (const [field, definition] of Object.entries(connector.settingSchema)) {
				const path = connectorSettingPath(connector, field);
				if (scope === 'site' && hasOverride(path) && fieldValue(path) === null) {
					nextErrors[path] = 'Choose a value before saving this override.';
					continue;
				}
				if (!needsRequiredSettings || !requiredConnectorSetting(definition)) continue;

				const value = connectorValue(path);
				const missing = value === undefined || value === null || (definition.type === 'string' && !String(value).trim());
				if (missing) {
					nextErrors[path] = `${definition.label} is required${enabled ? ' when this connector is enabled' : ''}.`;
				}
			}
		}

		fieldErrors = nextErrors;
		if (Object.keys(nextErrors).length === 0) return true;
		error = 'Review the highlighted settings before saving.';
		canRetry = false;
		const firstInvalidPath = Object.keys(nextErrors)[0] as SettingsPath;
		activeTab = firstInvalidPath.startsWith('analytics.')
			? 'analytics'
			: firstInvalidPath.startsWith('seo.') && !firstInvalidPath.startsWith('seo.defaultRobots.')
				? 'social'
				: 'indexing';
		const targetId = hasOverride(firstInvalidPath) || scope === 'application'
			? fieldId(firstInvalidPath)
			: `override-${fieldId(firstInvalidPath)}`;
		void tick().then(() => document.getElementById(targetId)?.focus());
		return false;
	}

	async function save(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		error = '';
		notice = '';
		if (!validate()) return;

		saving = true;
		const resourcePath = scope === 'application'
			? `/admin/applications/${encodeURIComponent(applicationId)}/content-optimization/settings`
			: `/site/${encodeURIComponent(siteId)}/content-optimization/settings`;
		const payload = scope === 'application'
			? settings
			: { version: 1, overrides: siteOverrides };
		try {
			const response = await api.request(resourcePath, {
				method: 'PUT',
				body: JSON.stringify(payload),
				...(scope === 'site' ? { siteHost: selectedSiteHost() } : {}),
			});
			if (response && typeof response === 'object') {
				applyConfigurationResponse(response as Record<string, unknown>);
				notice = 'Content optimization settings saved.';
			} else {
				const refreshed = await loadConfiguration(false, true);
				notice = refreshed
					? 'Content optimization settings saved.'
					: 'Settings were saved, but the refreshed values could not be loaded.';
			}
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to save content optimization settings.';
			canRetry = false;
		} finally {
			saving = false;
		}
	}

	function retryLoad(): void {
		if (applications.length === 0 && sites.length === 0) void loadScopes();
		else void loadConfiguration();
	}

	onMount(() => {
		void loadScopes();
	});
</script>

<PageHeader
	title="Content Optimization"
	description="Configure search metadata, crawler access, sitemaps, and consent-gated analytics."
/>

<section class="mb-6 flex flex-wrap items-end gap-4 rounded-xl border border-border bg-surface p-4" aria-label="Content optimization scope">
	<fieldset class="min-w-52">
		<legend class="mb-2 text-sm font-medium text-text">Settings scope</legend>
		<div class="flex flex-wrap gap-3">
			{#if applications.length > 0}
				<label for="optimization-scope-application" class="flex min-h-11 items-center gap-2 rounded-lg border border-border px-3 text-sm text-text">
					<input id="optimization-scope-application" type="radio" name="optimization-scope" value="application" checked={scope === 'application'} onchange={() => selectScope('application')} disabled={loading || saving} />
					<span>Global (Application)</span>
				</label>
			{/if}
			<label for="optimization-scope-site" class="flex min-h-11 items-center gap-2 rounded-lg border border-border px-3 text-sm text-text">
				<input id="optimization-scope-site" type="radio" name="optimization-scope" value="site" checked={scope === 'site'} onchange={() => selectScope('site')} disabled={loading || saving} />
				<span>Site</span>
			</label>
		</div>
	</fieldset>

	{#if scope === 'application'}
		<label for="optimization-application" class="block min-w-56 flex-1 space-y-1 text-sm">
			<span class="text-text-muted">Application</span>
			<select id="optimization-application" class="admin-input w-full" value={applicationId} onchange={(event) => { applicationId = event.currentTarget.value; void loadConfiguration(); }} disabled={loading || saving || applications.length === 0}>
				{#each applications as application (application.id)}
					<option value={String(application.id)}>{application.name || `Application ${application.id}`}</option>
				{/each}
			</select>
		</label>
	{:else}
		<label for="optimization-site" class="block min-w-56 flex-1 space-y-1 text-sm">
			<span class="text-text-muted">Site</span>
			<select id="optimization-site" class="admin-input w-full" value={siteId} onchange={(event) => { siteId = event.currentTarget.value; void loadConfiguration(); }} disabled={loading || saving || sites.length === 0}>
				{#each sites as site (site.id)}
					<option value={String(site.id)}>{site.name || site.domain || `Site ${site.id}`}</option>
				{/each}
			</select>
		</label>
		<label for="optimization-site-enabled" class="flex min-h-11 items-center gap-2 rounded-lg border border-border px-3 text-sm text-text">
			<input
				id="optimization-site-enabled"
				type="checkbox"
				checked={sitePluginEnabled}
				disabled={loading || saving || activationSaving || sites.length === 0}
				onchange={updateSiteActivation}
			/>
			<span>{activationSaving ? 'Updating plugin status…' : 'Enable Content Optimization on this site'}</span>
		</label>
	{/if}
</section>

{#if scope === 'site' && !sitePluginEnabled}
	<p class="mb-4 rounded-lg border border-border bg-surface p-4 text-sm text-text" role="status">
		Content Optimization is disabled on this Site. Saved SEO, crawler, and analytics settings are retained but do not affect public output until the plugin is re-enabled.
	</p>
{/if}

{#snippet fieldStatus(path: SettingsPath)}
	{#if scope === 'site'}
		<p class="mt-1 text-xs text-text-muted">
			<strong>{hasOverride(path) ? 'Overridden at this site' : 'Inherited from application'}</strong>
		</p>
	{/if}
{/snippet}

{#snippet textField(path: SettingsPath, label: string, hint: string = '')}
	<div class="space-y-2">
		{#if scope === 'site' && !hasOverride(path)}
			<p id={`${fieldId(path)}-label`} class="block text-sm font-medium text-text">{label}</p>
		{:else}
			<label for={fieldId(path)} class="block text-sm font-medium text-text">{label}</label>
		{/if}
		{@render fieldStatus(path)}
		{#if scope === 'site' && !hasOverride(path)}
			<p class="break-words rounded-md border border-border bg-surface-hover px-3 py-2 text-sm text-text">
				<span class="font-medium">Application default:</span> {displayValue(readPath(applicationDefaults, path))}
			</p>
			<button
				id={`override-${fieldId(path)}`}
				type="button"
				class="admin-btn admin-btn-sm admin-btn-secondary"
				aria-label={`Override ${label}`}
				aria-describedby={fieldErrors[path] ? `${fieldId(path)}-error` : undefined}
				onclick={() => beginOverride(path)}
			>
				Override {label}
			</button>
		{:else}
			<input
				id={fieldId(path)}
				name={path}
				type="text"
				class="admin-input w-full"
				value={String(fieldValue(path) ?? '')}
				required={isRequiredPath(path)}
				aria-invalid={fieldErrors[path] ? 'true' : undefined}
				aria-describedby={fieldDescription(path, hint)}
				oninput={(event) => updateField(path, event.currentTarget.value)}
			/>
			{#if scope === 'site'}
				<button type="button" class="admin-btn admin-btn-sm admin-btn-secondary" onclick={() => resetOverride(path)}>
					Reset {label} to inherited
				</button>
			{/if}
		{/if}
		{#if hint}<p id={`${fieldId(path)}-hint`} class="text-xs text-text-muted">{hint}</p>{/if}
		{#if fieldErrors[path]}<p id={`${fieldId(path)}-error`} class="text-sm text-danger">{fieldErrors[path]}</p>{/if}
	</div>
{/snippet}

{#snippet choiceField(path: SettingsPath, label: string, options: ChoiceOption[], hint: string = '')}
	<div class="space-y-2">
		{#if scope === 'site' && !hasOverride(path)}
			<p id={`${fieldId(path)}-label`} class="block text-sm font-medium text-text">{label}</p>
		{:else}
			<label for={fieldId(path)} class="block text-sm font-medium text-text">{label}</label>
		{/if}
		{@render fieldStatus(path)}
		{#if scope === 'site' && !hasOverride(path)}
			<p class="rounded-md border border-border bg-surface-hover px-3 py-2 text-sm text-text">
				<span class="font-medium">Application default:</span> {displayValue(readPath(applicationDefaults, path), options)}
			</p>
			<button
				id={`override-${fieldId(path)}`}
				type="button"
				class="admin-btn admin-btn-sm admin-btn-secondary"
				aria-label={`Override ${label}`}
				aria-describedby={fieldErrors[path] ? `${fieldId(path)}-error` : undefined}
				onclick={() => beginOverride(path)}
			>
				Override {label}
			</button>
		{:else}
			<select
				id={fieldId(path)}
				name={path}
				class="admin-input w-full"
				value={String(fieldValue(path) ?? '')}
				aria-invalid={fieldErrors[path] ? 'true' : undefined}
				aria-describedby={fieldDescription(path, hint)}
				onchange={(event) => updateChoice(path, event.currentTarget.value)}
			>
				{#if scope === 'site' && fieldValue(path) === null}<option value="">Choose a value</option>{/if}
				{#each options as option (String(option.value))}
					<option value={String(option.value)}>{option.label}</option>
				{/each}
			</select>
			{#if scope === 'site'}
				<button type="button" class="admin-btn admin-btn-sm admin-btn-secondary" onclick={() => resetOverride(path)}>
					Reset {label} to inherited
				</button>
			{/if}
		{/if}
		{#if hint}<p id={`${fieldId(path)}-hint`} class="text-xs text-text-muted">{hint}</p>{/if}
		{#if fieldErrors[path]}<p id={`${fieldId(path)}-error`} class="text-sm text-danger">{fieldErrors[path]}</p>{/if}
	</div>
{/snippet}

{#snippet connectorField(connector: AnalyticsConnector, path: string, label: string, kind: 'enabled' | 'string' | 'boolean' | 'enum', definition?: ConnectorSettingSchema)}
	{@const id = fieldId(path)}
	{@const enabled = connectorValue(connectorEnabledPath(connector), false) === true}
	{@const required = kind !== 'enabled' && definition !== undefined && requiredConnectorSetting(definition) && enabled}
	{@const value = kind === 'enabled' ? connectorValue(path, false) : fieldValue(path)}
	{@const hint = kind === 'enabled'
		? 'Connector tags remain gated by analytics consent. Enabling this does not change the site Content Security Policy.'
		: definition?.type === 'string'
			? `${definition.maxLength ? `Maximum ${definition.maxLength} characters. ` : ''}${definition.pattern ? 'Additional format rules are checked on save. ' : ''}The connector validates this value when saved.`
			: ''}
	{@const describedBy = fieldDescription(path, hint)}
	<div class="min-w-0 space-y-2">
		{#if scope === 'site' && !hasOverride(path)}
			<p id={`${id}-label`} class="block text-sm font-medium text-text">
				{label}{#if required}<span aria-hidden="true"> *</span>{/if}
			</p>
		{:else}
			<label for={id} class="block text-sm font-medium text-text">
				{label}{#if required}<span aria-hidden="true"> *</span>{/if}
			</label>
		{/if}
		{@render fieldStatus(path)}
		{#if scope === 'site' && !hasOverride(path)}
			<p class="break-words rounded-md border border-border bg-surface-hover px-3 py-2 text-sm text-text">
				<span class="font-medium">Application default:</span> {displayValue(kind === 'enabled' ? connectorValue(path, false) : readPath(applicationDefaults, path), kind === 'enum' ? definition?.options : undefined)}
			</p>
			<button
				id={`override-${id}`}
				type="button"
				class="admin-btn admin-btn-sm admin-btn-secondary"
				aria-label={`Override ${label}`}
				aria-describedby={fieldErrors[path] ? `${id}-error` : undefined}
				onclick={() => beginOverride(path, kind === 'string' ? '' : null)}
			>
				Override {label}
			</button>
		{:else if kind === 'string'}
			<input
				id={id}
				name={path}
				type="text"
				class="admin-input w-full"
				value={String(value ?? '')}
				maxlength={definition?.maxLength}
				required={required}
				aria-invalid={fieldErrors[path] ? 'true' : undefined}
				aria-describedby={describedBy}
				oninput={(event) => updateField(path, event.currentTarget.value)}
			/>
		{:else}
			<select
				id={id}
				name={path}
				class="admin-input w-full"
				value={String(value ?? '')}
				required={required}
				aria-invalid={fieldErrors[path] ? 'true' : undefined}
				aria-describedby={describedBy}
				onchange={(event) => updateConnectorChoice(path, event.currentTarget.value, kind === 'enabled' || kind === 'boolean' ? 'boolean' : 'enum')}
			>
				{#if scope === 'site' && value === null}<option value="">Choose a value</option>{/if}
				{#if kind === 'enabled' || kind === 'boolean'}
					<option value="true">Enabled</option>
					<option value="false">Disabled</option>
				{:else if definition?.options}
					{#each Object.entries(definition.options) as [optionValue, optionLabel] (optionValue)}
						<option value={optionValue}>{optionLabel}</option>
					{/each}
				{/if}
			</select>
		{/if}
		{#if scope === 'site' && hasOverride(path)}
			<button type="button" class="admin-btn admin-btn-sm admin-btn-secondary" onclick={() => resetOverride(path)}>
				Reset {label} to inherited
			</button>
		{/if}
		{#if hint}<p id={`${id}-hint`} class="text-xs text-text-muted">{hint}</p>{/if}
		{#if fieldErrors[path]}<p id={`${id}-error`} class="text-sm text-danger">{fieldErrors[path]}</p>{/if}
	</div>
{/snippet}

{#if error}
	<div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-danger/40 bg-danger/5 p-3 text-sm text-danger" role="alert">
		<p>{error}</p>
		{#if canRetry}<button type="button" class="admin-btn admin-btn-sm admin-btn-secondary" onclick={retryLoad}>Retry</button>{/if}
	</div>
{/if}

{#if loadError}
	<div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-danger/40 bg-danger/5 p-3 text-sm text-danger" role="alert">
		<p>{loadError}</p>
		{#if canRetry}<button type="button" class="admin-btn admin-btn-sm admin-btn-secondary" onclick={retryLoad}>Retry</button>{/if}
	</div>
{/if}

{#if notice}
	<p class="mb-4 rounded-lg border border-border bg-surface px-3 py-2 text-sm text-text" role="status">{notice}</p>
{/if}

{#if loading}
	<p class="py-6 text-sm text-text-muted" role="status">Loading content optimization settings...</p>
{:else if loadError}
	<p class="py-6 text-sm text-text-muted">Settings are unavailable until they load successfully.</p>
{:else if !hasScopeTarget}
	<p class="py-6 text-sm text-text-muted">No {scope === 'application' ? 'application' : 'site'} is available for this scope.</p>
{:else}
	<form class="space-y-5" novalidate onsubmit={save}>
		<div class="border-b border-border">
			<div class="-mb-px flex gap-4 overflow-x-auto" role="tablist" aria-label="Content optimization settings tabs">
				{#each TABS as tab, index (tab.value)}
					<button
						id={`content-optimization-tab-${tab.value}`}
						aria-controls={`content-optimization-panel-${tab.value}`}
						type="button"
						role="tab"
						aria-selected={activeTab === tab.value}
						tabindex={activeTab === tab.value ? 0 : -1}
						class="admin-tab shrink-0"
						class:active={activeTab === tab.value}
						onclick={() => (activeTab = tab.value)}
						onkeydown={(event) => onTabKeydown(event, index)}
					>
						{tab.label}
					</button>
				{/each}
			</div>
		</div>

		<div id="content-optimization-panel-analytics" class="admin-card space-y-6 p-4 sm:p-6" role="tabpanel" aria-labelledby="content-optimization-tab-analytics" tabindex="0" hidden={activeTab !== 'analytics'}>
			<div>
				<h2 class="text-lg font-semibold text-text">Analytics &amp; Tagging</h2>
				<p class="mt-1 max-w-3xl text-sm text-text-muted">GA4 and Clarity tags are fail-closed: they load only after a visitor explicitly grants analytics consent. Enabling a provider here does not grant consent.</p>
			</div>
			<div class="grid gap-5 md:grid-cols-2">
				<div class="space-y-2">
					{@render choiceField('analytics.ga4.enabled', 'Google Analytics 4', BOOLEAN_OPTIONS, 'Enable the integration for visitors who have granted analytics consent.')}
					<a href="https://analytics.google.com/analytics/web/" target="_blank" rel="noopener noreferrer" class="text-sm text-accent underline">Open GA4 dashboard (new tab)</a>
				</div>
				{@render textField('analytics.ga4.measurementId', 'GA4 measurement ID', 'For example, G-XXXXXXXXXX. Required only when GA4 is enabled.')}
				<div class="space-y-2">
					{@render choiceField('analytics.clarity.enabled', 'Microsoft Clarity', BOOLEAN_OPTIONS, 'Enable the integration for visitors who have granted analytics consent.')}
					<a href="https://clarity.microsoft.com/" target="_blank" rel="noopener noreferrer" class="text-sm text-accent underline">Open Clarity dashboard (new tab)</a>
				</div>
				{@render textField('analytics.clarity.projectId', 'Clarity project ID', 'Required only when Clarity is enabled.')}
			</div>
			<aside class="rounded-lg border border-border bg-surface-hover p-4 text-sm text-text">
				<h3 class="font-semibold">Content Security Policy guidance</h3>
				<p class="mt-1 text-text-muted">Review these narrow requirements in the site's existing policy; this screen never changes CSP automatically.</p>
				<ul class="mt-3 list-disc space-y-2 pl-5 text-text-muted">
					<li><strong>GA4:</strong> <code>script-src https://www.googletagmanager.com/gtag/js</code>; <code>connect-src https://www.google-analytics.com https://*.google-analytics.com https://*.analytics.google.com</code>; <code>img-src https://*.google-analytics.com</code>.</li>
					<li><strong>Clarity:</strong> <code>script-src https://www.clarity.ms/tag/ https://scripts.clarity.ms/</code>; <code>connect-src https://*.clarity.ms</code>; <code>img-src https://c.clarity.ms https://c.bing.com</code>.</li>
				</ul>
				<p class="mt-3 text-text-muted">A policy using <code>'strict-dynamic'</code> may trust scripts loaded by a nonced script transitively, so review that mode before enabling third-party analytics. Verify changes in report-only mode.</p>
			</aside>
			{#if availableConnectors.length > 0}
				<section class="space-y-4" aria-labelledby="optimization-third-party-connectors-heading">
					<div>
						<h3 id="optimization-third-party-connectors-heading" class="text-base font-semibold text-text">Third-party connectors</h3>
						<p class="mt-1 text-sm text-text-muted">Plugin connectors use schema-defined settings and the same analytics consent gate. Only connectors available to the selected scope are listed.</p>
					</div>
					<div class="grid min-w-0 gap-4 xl:grid-cols-2">
						{#each availableConnectors as connector (connector.key)}
							{@const enabledPath = connectorEnabledPath(connector)}
							{@const connectorId = fieldId(connectorConfigPath(connector))}
							{@const hasStoredConfiguration = connectorHasConfiguration(connector)}
							<article class="min-w-0 space-y-5 rounded-xl border border-border bg-surface p-4 sm:p-5" aria-labelledby={`${connectorId}-heading`}>
								<header>
								<h4 id={`${connectorId}-heading`} class="break-words text-base font-semibold text-text">{connector.label}</h4>
								<p class="mt-1 break-words text-xs text-text-muted">Connector key: <code>{connector.key}</code></p>
								{#if isSafeHttpsUrl(connector.dashboardUrl)}
									<a href={connector.dashboardUrl} target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-sm text-accent underline">Open {connector.label} dashboard (new tab)</a>
								{/if}
								{#if hasStoredConfiguration}
									<button type="button" class="admin-btn admin-btn-sm admin-btn-secondary mt-2" onclick={() => removeConnectorConfiguration(connector)}>
										{scope === 'site' ? `Reset all ${connector.label} site overrides` : `Remove ${connector.label} configuration`}
									</button>
								{/if}
							</header>
								<div class="grid min-w-0 gap-4 md:grid-cols-2">
									{@render connectorField(connector, enabledPath, 'Enabled state', 'enabled')}
									{#each Object.entries(connector.settingSchema) as [field, definition] (field)}
										{@render connectorField(connector, connectorSettingPath(connector, field), definition.label, definition.type, definition)}
									{/each}
								</div>
								<aside class="min-w-0 rounded-lg border border-border bg-surface-hover p-4 text-sm text-text" aria-labelledby={`${connectorId}-csp-heading`}>
									<h5 id={`${connectorId}-csp-heading`} class="font-semibold">Content Security Policy guidance</h5>
									<p class="mt-1 text-xs text-text-muted">Review these declared sources against the site's current policy. They are guidance only; this screen never updates CSP automatically.</p>
									<dl class="mt-3 grid min-w-0 gap-3">
						{#each connectorCspDirectives(connector) as [directive, sources] (directive)}
											<div class="min-w-0">
												<dt class="font-medium">{directive}</dt>
												<dd class="mt-1 break-all text-xs text-text-muted">
													{#if sources.length > 0}
														{#each sources as source (source)}<code class="mr-2">{source}</code>{/each}
													{:else}No additional sources declared.{/if}
												</dd>
											</div>
										{/each}
									</dl>
								</aside>
							</article>
						{/each}
					</div>
				</section>
			{/if}
		</div>

		<div id="content-optimization-panel-indexing" class="admin-card space-y-6 p-4 sm:p-6" role="tabpanel" aria-labelledby="content-optimization-tab-indexing" tabindex="0" hidden={activeTab !== 'indexing'}>
			<div>
				<h2 class="text-lg font-semibold text-text">Indexing</h2>
				<p class="mt-1 text-sm text-text-muted">Control default indexing directives, sitemap generation, and AI crawler access.</p>
			</div>
			<div class="grid gap-5 md:grid-cols-2">
				{@render choiceField('seo.defaultRobots.index', 'Search indexing', INDEX_OPTIONS)}
				{@render choiceField('seo.defaultRobots.follow', 'Link following', FOLLOW_OPTIONS)}
				{@render choiceField('sitemap.enabled', 'XML sitemap', BOOLEAN_OPTIONS, 'When enabled, sitemap output is available to search engines.')}
				{@render choiceField('crawlers.aiAccess', 'AI crawler access', AI_ACCESS_OPTIONS, 'This setting describes the configured crawler policy; it does not alter server access controls.')}
			</div>
			<aside class="rounded-lg border border-border bg-surface p-4" aria-label="Shared X-Robots-Tag policy">
				<h3 class="font-semibold text-text">Shared X-Robots-Tag policy</h3>
				<p class="mt-1 text-sm text-text-muted">HTTP X-Robots-Tag rules are managed by the shared Response Headers policy and are not changed here. A matching response header can be more restrictive than page-level robots metadata.</p>
				{#if hasPotentialRobotsHeaderConflict}
					<p class="mt-3 rounded-md border border-border bg-surface p-3 text-sm text-text" role="status">
						A stored X-Robots-Tag rule includes <code>noindex</code> while the effective Content Optimization default is set to index. Conditions and Application/Site inheritance determine whether that rule applies; review the shared policy to confirm its effective value.
					</p>
				{/if}
				{#if robotsHeaderRules.length > 0}
					<ul class="mt-3 space-y-2">
						{#each robotsHeaderRules as rule, index (`${rule.scope}:${index}`)}
							<li class="flex flex-wrap items-center gap-2 text-sm">
								<span class="font-medium">{rule.scope}:</span>
								<code>{rule.action === 'set' ? `X-Robots-Tag: ${rule.value ?? ''}` : 'Remove X-Robots-Tag'}</code>
								<span class="text-text-muted">{rule.conditional ? 'Conditional' : 'Always applied'}</span>
								{#if rule.required}<span class="rounded border border-border px-2 py-1">Required</span>{/if}
							</li>
						{/each}
					</ul>
				{:else}
					<p class="mt-3 text-sm text-text-muted">No application or site X-Robots-Tag rules are currently configured.</p>
				{/if}
			</aside>
		</div>

		<div id="content-optimization-panel-social" class="admin-card space-y-6 p-4 sm:p-6" role="tabpanel" aria-labelledby="content-optimization-tab-social" tabindex="0" hidden={activeTab !== 'social'}>
			<div>
				<h2 class="text-lg font-semibold text-text">Social &amp; Defaults</h2>
				<p class="mt-1 text-sm text-text-muted">Set fallback metadata used when content does not provide its own values.</p>
			</div>
			<div class="grid gap-5 md:grid-cols-2">
				{@render textField('seo.titleTemplate', 'Title template', 'Use {pageTitle} for the content title and {siteName} for the site name.')}
				{@render textField('seo.defaultDescription', 'Default description', 'Used when a page does not define a search description.')}
				<div class="space-y-2">
					{@render textField('seo.defaultSocialImage', 'Default social image URL', 'A site-hosted DAM asset URL or absolute HTTPS URL.')}
					<div class="flex flex-wrap items-center gap-2">
						<button
							type="button"
							class="admin-btn admin-btn-sm admin-btn-secondary"
							onclick={openSocialImagePicker}
							disabled={saving}
						>
							Choose from DAM
						</button>
					</div>
				</div>
			</div>
		</div>

		<div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
			<p class="text-sm text-text-muted">Changes are applied only when you save.</p>
			<button type="submit" class="admin-btn admin-btn-primary" disabled={saving}>
				{saving ? 'Saving...' : scope === 'application' ? 'Save application defaults' : 'Save site overrides'}
			</button>
		</div>
	</form>
{/if}

<MediaPickerModal
	open={socialImagePickerOpen}
	onClose={() => (socialImagePickerOpen = false)}
	onSelect={handleSocialImageSelect}
	accept="image/*"
	allowExternalUrl={true}
	initialUrl={String(fieldValue('seo.defaultSocialImage') ?? '')}
	apiEndpoint="/api/admin/assets"
	apiBase={api.baseUrl}
	fetchHeaders={socialImagePickerHeaders}
	title="Choose default social image"
/>
