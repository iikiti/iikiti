import SeoPreviewControl from './SeoPreviewControl.svelte';
import { normalizeMediaValue } from '../../../../assets/svelte/shared/lib/mediaHelpers.js';

const PREFIX = 'iikiti_content_optimization_';
const PREVIEW_CONTROL = 'iikiti-content-optimization-preview';

const fields = {
	title: `${PREFIX}title`,
	description: `${PREFIX}description`,
	canonicalUrl: `${PREFIX}canonical_url`,
	robotsIndex: `${PREFIX}robots_index`,
	robotsFollow: `${PREFIX}robots_follow`,
	openGraphTitle: `${PREFIX}og_title`,
	openGraphDescription: `${PREFIX}og_description`,
	openGraphType: `${PREFIX}og_type`,
	openGraphImage: `${PREFIX}og_image`,
	twitterCard: `${PREFIX}twitter_card`,
	twitterTitle: `${PREFIX}twitter_title`,
	twitterDescription: `${PREFIX}twitter_description`,
	twitterImage: `${PREFIX}twitter_image`,
	preview: `${PREFIX}preview`,
};

const asText = (value) => (typeof value === 'string' ? value : '');

function previewDefaults() {
	const defaults = typeof window !== 'undefined' ? window.iikiti?.config?.seoPreviewDefaults ?? {} : {};
	return {
		pageTitle: asText(defaults.pageTitle) || 'Page title',
		siteName: asText(defaults.siteName),
		titleTemplate: asText(defaults.titleTemplate) || '{pageTitle}',
		description: asText(defaults.description),
		image: defaults.image ?? '',
	};
}

export function resolveTitleTemplate(defaults) {
	return defaults.titleTemplate
		.replace(/\{pageTitle\}/g, () => defaults.pageTitle)
		.replace(/\{siteName\}/g, () => defaults.siteName)
		.trim() || defaults.pageTitle;
}

function resolveValue(value, fallback = '') {
	return asText(value).trim() || fallback;
}

function resolveMediaSetting(value, fallback = '') {
	const normalized = normalizeMediaValue(value);
	if (normalized.source !== 'empty') {
		return value;
	}
	return fallback;
}

function field(key, label, type, options = {}) {
	return { kind: 'field', path: 'settings', field: { key, label, type, ...options } };
}

function group(id, label, nodes) {
	return { kind: 'group', id, label, nodes };
}

export function registerContentOptimizationEditor(sidebar) {
	if (!sidebar?.registerSettingsSection || !sidebar?.registerFieldControl) return;

	sidebar.registerFieldControl(PREVIEW_CONTROL, SeoPreviewControl);
	sidebar.registerSettingsSection({
		id: 'iikiti-content-optimization-seo',
		label: 'SEO',
		icon: 'search',
		order: 30,
		targetTypes: ['page', 'site_home'],
		build: ({ values = {} }) => {
			const defaults = previewDefaults();
			const title = resolveValue(values[fields.title], resolveTitleTemplate(defaults));
			const description = resolveValue(values[fields.description], defaults.description);
			const openGraphTitle = resolveValue(values[fields.openGraphTitle], title);
			const openGraphDescription = resolveValue(values[fields.openGraphDescription], description);
			const openGraphImage = resolveMediaSetting(values[fields.openGraphImage], defaults.image);

			return [
			group('search', 'Search', [
				field(fields.title, 'Search title', 'text', { placeholder: 'Page title in search results', characterGuide: 60 }),
				field(fields.description, 'Search description', 'textarea', { rows: 3, placeholder: 'A concise summary of this page', characterGuide: 160 }),
				field(fields.canonicalUrl, 'Canonical URL', 'url', { placeholder: 'https://example.com/page' }),
			]),
			group('robots', 'Search engine behavior', [
				field(fields.robotsIndex, 'Indexing', 'select', {
					options: [
						{ value: '', label: 'Use site default' },
						{ value: 'index', label: 'Allow indexing' },
						{ value: 'noindex', label: 'Prevent indexing' },
					],
				}),
				field(fields.robotsFollow, 'Link following', 'select', {
					options: [
						{ value: '', label: 'Use site default' },
						{ value: 'follow', label: 'Allow link following' },
						{ value: 'nofollow', label: 'Prevent link following' },
					],
				}),
			]),
			group('open-graph', 'Open Graph', [
				field(fields.openGraphTitle, 'Social title', 'text', { characterGuide: 60 }),
				field(fields.openGraphDescription, 'Social description', 'textarea', { rows: 3, characterGuide: 160 }),
				field(fields.openGraphType, 'Content type', 'select', {
					options: [
						{ value: '', label: 'Use site default' },
						{ value: 'website', label: 'Website' },
						{ value: 'article', label: 'Article' },
					],
				}),
				field(fields.openGraphImage, 'Social image', 'media', { accept: 'image/*', allowExternalUrl: true }),
			]),
			group('twitter', 'X / Twitter', [
				field(fields.twitterCard, 'Card style', 'select', {
					options: [
						{ value: '', label: 'Use site default' },
						{ value: 'summary', label: 'Summary' },
						{ value: 'summary_large_image', label: 'Large image summary' },
					],
				}),
				field(fields.twitterTitle, 'Card title', 'text', { characterGuide: 60 }),
				field(fields.twitterDescription, 'Card description', 'textarea', { rows: 3, characterGuide: 160 }),
				field(fields.twitterImage, 'Card image', 'media', { accept: 'image/*', allowExternalUrl: true }),
			]),
			group('preview', 'Previews', [
				field(fields.preview, 'Search and social previews (read-only)', PREVIEW_CONTROL, {
					readOnlyDisplay: true,
					preview: {
						title,
						description,
						canonicalUrl: asText(values[fields.canonicalUrl]),
						openGraphTitle,
						openGraphDescription,
						openGraphType: asText(values[fields.openGraphType]) || 'website',
						openGraphImage,
						twitterCard: asText(values[fields.twitterCard]) || 'summary_large_image',
						twitterTitle: resolveValue(values[fields.twitterTitle], openGraphTitle),
						twitterDescription: resolveValue(values[fields.twitterDescription], openGraphDescription),
						twitterImage: resolveMediaSetting(values[fields.twitterImage], openGraphImage),
					},
				}),
			]),
			];
		},
	});
}

if (typeof window !== 'undefined') {
	const register = () => registerContentOptimizationEditor(window.iikiti?.editor?.sidebar);
	if (window.iikiti?.editor?.sidebar) {
		register();
	} else if (typeof window.iikiti?.whenReady === 'function') {
		window.iikiti.whenReady('editor.sidebar').then(register);
	}
}
