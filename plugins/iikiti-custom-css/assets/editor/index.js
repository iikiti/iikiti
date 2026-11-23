import CssCodeControl from './CssCodeControl.svelte';

export function registerCustomCssEditor(sidebar) {
	if (!sidebar?.registerFieldControl) return;
	sidebar.registerFieldControl('css', CssCodeControl, { priority: 10 });
}

if (typeof window !== 'undefined') {
	const register = () => registerCustomCssEditor(window.iikiti?.editor?.sidebar);
	if (window.iikiti?.editor?.sidebar) {
		register();
	} else if (typeof window.iikiti?.whenReady === 'function') {
		window.iikiti.whenReady('editor.sidebar').then(register);
	}
}
