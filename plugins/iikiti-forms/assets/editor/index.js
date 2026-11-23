import FormContainerPreview from './FormContainerPreview.svelte';
import FormFieldPreview from './FormFieldPreview.svelte';

export function registerFormsEditor(editor) {
	if (!editor?.registerBlockPreview) return;
	for (const containerType of ['form', 'fieldset']) {
		editor.registerBlockPreview(containerType, FormContainerPreview);
	}
	for (const fieldType of ['button', 'input', 'textarea', 'select', 'range', 'checkbox', 'radio', 'legend']) {
		editor.registerBlockPreview(fieldType, FormFieldPreview);
	}
}

if (typeof window !== 'undefined') {
	const register = () => registerFormsEditor(window.iikiti?.editor);
	if (window.iikiti?.editor?.registerBlockPreview) {
		register();
	} else if (typeof window.iikiti?.whenReady === 'function') {
		window.iikiti.whenReady('editor.sidebar').then(register);
	}
}
