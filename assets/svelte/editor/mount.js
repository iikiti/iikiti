import { mount } from 'svelte';
import Editor from './Editor.svelte';

/**
 * Mounts the live block editor onto `container` with the given bootstrap config.
 * Called by the iikiti framework when the editor is activated.
 */
export function launchEditor(container, config) {
	mount(Editor, {
		target: container,
		props: { config: config || {} },
	});
}
