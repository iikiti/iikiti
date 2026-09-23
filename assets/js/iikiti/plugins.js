import { loader, loadWithStrategy, onInteraction } from './loader.js';

/**
 * @typedef {object} PluginDefinition
 * @property {string} name
 * @property {string} [entry]
 * @property {string} [css]
 * @property {'async'|'defer'|'complete'|'interaction'} [strategy]
 * @property {string[]} [deps]
 */

const plugins = [];
let started = false;

export const pluginRegistry = {
	register(def) {
		plugins.push(def);
	},
	list: () => plugins,
};

export async function startPlugins() {
	if (started) return;
	started = true;
	for (const def of plugins) {
		if (def.css) {
			await loader.loadStyle(def.css);
		}
		if (def.entry) {
			const strategy = def.strategy ?? 'complete';
			if (strategy === 'interaction') {
				await onInteraction();
			}
			await loadWithStrategy(def.entry, { strategy });
		}
	}
}
