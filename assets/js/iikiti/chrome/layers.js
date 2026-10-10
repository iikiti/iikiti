/**
 * Z-index layer scale, read from the `--iikiti-z-*` tokens in assets/styles/ui.css.
 *
 * The CSS tokens are the single source of truth. The numbers below are only
 * fallbacks for contexts where the stylesheet has not loaded yet; keep them in
 * sync with ui.css when the scale changes.
 */

const FALLBACKS = Object.freeze({
	content: 0,
	contentRaised: 10,
	contentOverlay: 20,
	editorCanvas: 100,
	editorControls: 200,
	editorPanel: 300,
	shellSidebar: 500,
	shellOverlay: 600,
	shellHeader: 700,
	chrome: 1000,
	popover: 2000,
	menu: 3950,
	floating: 3000,
	modal: 3500,
	tour: 3900,
	tooltip: 4000,
	toast: 4500,
});

const TOKEN_NAMES = Object.freeze({
	content: '--iikiti-z-content',
	contentRaised: '--iikiti-z-content-raised',
	contentOverlay: '--iikiti-z-content-overlay',
	editorCanvas: '--iikiti-z-editor-canvas',
	editorControls: '--iikiti-z-editor-controls',
	editorPanel: '--iikiti-z-editor-panel',
	shellSidebar: '--iikiti-z-shell-sidebar',
	shellOverlay: '--iikiti-z-shell-overlay',
	shellHeader: '--iikiti-z-shell-header',
	chrome: '--iikiti-z-chrome',
	popover: '--iikiti-z-popover',
	menu: '--iikiti-z-menu',
	floating: '--iikiti-z-floating',
	modal: '--iikiti-z-modal',
	tour: '--iikiti-z-tour',
	tooltip: '--iikiti-z-tooltip',
	toast: '--iikiti-z-toast',
});

/**
 * Resolve a layer from its CSS token, falling back to the documented value.
 * @param {keyof typeof TOKEN_NAMES} layer
 * @returns {number}
 */
export function layerValue(layer) {
	if (typeof window === 'undefined' || typeof document === 'undefined') {
		return FALLBACKS[layer];
	}
	const raw = getComputedStyle(document.documentElement).getPropertyValue(TOKEN_NAMES[layer]);
	const value = parseInt(raw, 10);
	return Number.isFinite(value) && value >= 0 ? value : FALLBACKS[layer];
}

/** Layer names mapped to their CSS custom property, for plugins and tooling. */
export const LAYER_TOKENS = TOKEN_NAMES;
