import { mount } from 'svelte';
import BlockEditor from './svelte/controllers/BlockEditor.svelte';

// any CSS you import will output into a single css file (app.css in this case)
import './styles/app.css';

// start the Stimulus application
import './bootstrap';

import './main.js';

mount(BlockEditor, {
	target: document.querySelector('[data-component="BlockEditorComponent"]'),
});