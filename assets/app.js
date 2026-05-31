import { mount } from 'svelte';
import BlockEditor from './svelte/controllers/BlockEditor.svelte';

// any CSS you import will output into a single css file (app.css in this case)
import './styles/app.css';

// start the Stimulus application
import './bootstrap';

import './main.js';

const rootElement = document.querySelector('[data-component="BlockEditorComponent"]');

if (rootElement) {
	const regionDefinitions = [];
	const regionElements = rootElement.querySelectorAll('[data-region-id]');

	regionElements.forEach((el) => {
		const id = el.getAttribute('data-region-id');
		const name = el.getAttribute('data-region-name') || id;
		const allowedTypesAttr = el.getAttribute('data-allowed-types');
		let allowedBlockTypes = ['hero', 'container', 'text', 'button'];

		if (allowedTypesAttr) {
			try {
				allowedBlockTypes = JSON.parse(allowedTypesAttr);
			} catch (e) {
				console.warn('Failed to parse allowed types for region:', id, e);
			}
		}

		regionDefinitions.push({
			id,
			name,
			allowedBlockTypes,
		});
	});

	const props = {};
	if (regionDefinitions.length > 0) {
		props.regionDefinitions = regionDefinitions;
	}

	mount(BlockEditor, {
		target: rootElement,
		props,
	});
}