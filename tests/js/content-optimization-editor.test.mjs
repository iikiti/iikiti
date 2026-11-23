import { test, expect, mock } from 'bun:test';

const previewSveltePath = new URL('../../plugins/iikiti-content-optimization/assets/editor/SeoPreviewControl.svelte', import.meta.url).pathname;
mock.module(previewSveltePath, () => ({ default: function MockSeoPreviewControl() {} }));

const { registerContentOptimizationEditor, resolveTitleTemplate } = await import('../../plugins/iikiti-content-optimization/assets/editor/index.js');

test('resolveTitleTemplate preserves regex replacement tokens like $&, $1, and $$', () => {
	const resolved = resolveTitleTemplate({
		titleTemplate: '{pageTitle} | {siteName}',
		pageTitle: 'Save $& and $$100',
		siteName: 'Brand $1 Store',
	});

	expect(resolved).toBe('Save $& and $$100 | Brand $1 Store');
});

test('registerContentOptimizationEditor registers preview control as readOnlyDisplay with summary_large_image fallback', () => {
	const controls = new Map();
	let section = null;
	registerContentOptimizationEditor({
		registerFieldControl: (name, component) => controls.set(name, component),
		registerSettingsSection: (def) => { section = def; },
	});

	expect(controls.has('iikiti-content-optimization-preview')).toBe(true);
	expect(section?.id).toBe('iikiti-content-optimization-seo');
	expect(section?.targetTypes).toEqual(['page', 'site_home']);

	const groups = section.build({
		values: {
			iikiti_content_optimization_title: 'Pricing $& Plans',
			iikiti_content_optimization_canonical_url: '/pricing',
		},
	});
	const previewGroup = groups.find((group) => group.id === 'preview');
	const previewField = previewGroup.nodes[0].field;

	expect(previewField.readOnlyDisplay).toBe(true);
	expect(previewField.preview.title).toBe('Pricing $& Plans');
	expect(previewField.preview.canonicalUrl).toBe('/pricing');
	expect(previewField.preview.twitterCard).toBe('summary_large_image');
});
