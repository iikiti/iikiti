import { describe, expect, test } from 'bun:test';
import { blockRootClass, customCssStyleText, styleTargetSelector, styleText } from './styleValues.js';

	describe('block custom CSS styles', () => {
	test('targets the icon element in editor previews', () => {
		expect(styleTargetSelector({ id: 'icon-1', type: 'icon' })).toBe('[data-block-id="icon-1"] .iikiti-icon-block');
		expect(styleTargetSelector({ id: 'container-1', type: 'container' })).toBe('[data-style-target="container-1"] .iikiti-container');
	});

	test('preserves descendant widget classes for nested container CSS', () => {
		const containerSelector = styleTargetSelector({ id: 'container-1', type: 'container' });
		const childClass = blockRootClass({ type: 'icon', element: { cssClass: 'feature-icon' } });

		expect(childClass).toBe('iikiti-block iikiti-block--icon feature-icon');
		expect(`${containerSelector} .feature-icon`).toBe('[data-style-target="container-1"] .iikiti-container .feature-icon');
	});

	test('keeps custom CSS out of inline styles and scopes it at each breakpoint', () => {
		const node = {
			id: 'feature-card',
			style: {
				base: { color: 'red', customCss: '&:hover { color: blue; }', customCssCompiled: '.iikiti-custom-css-scope-placeholder{&:hover{color:blue}}' },
				md: { customCss: 'opacity: 0.8;', customCssCompiled: '.iikiti-custom-css-scope-placeholder{opacity:.8}' },
			},
		};

		expect(styleText(node.style, 'container')).toBe('color:red');
		expect(customCssStyleText(node, { base: 0, md: 768 }, '[data-style-target="feature-card"]'))
			.toBe('[data-style-target="feature-card"]{&:hover{color:blue}}\n@media (min-width:768px){[data-style-target="feature-card"]{opacity:.8}}');
	});
});
