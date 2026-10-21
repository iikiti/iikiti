/** @type {Array<{provider:Function,priority:number,seq:number}>} */
const providers = [];
let sequence = 0;

/**
 * Register a provider of CodeMirror CSS completion options.
 *
 * @param {(context:any)=>Array<Record<string,unknown>>|Promise<Array<Record<string,unknown>>>} provider
 * @param {{priority?:number}} [options]
 * @returns {()=>void} Unregisters this provider.
 */
export function registerCssAutocompleteProvider(provider, options = {}) {
	if (typeof provider !== 'function') return () => {};
	const entry = { provider, priority: Number(options.priority ?? 0), seq: ++sequence };
	providers.push(entry);
	return () => {
		const index = providers.indexOf(entry);
		if (index >= 0) providers.splice(index, 1);
	};
}

/** @param {()=>Record<string,unknown>} getSettingsContext */
export function createSettingsCssCompletionSource(getSettingsContext) {
	return async (completionContext) => {
		const match = completionContext.matchBefore(/[-_a-zA-Z0-9]*/);
		if (!completionContext.explicit && (!match || match.from === match.to)) return null;

		const settingsContext = getSettingsContext();
		const providerContext = {
			completionContext,
			targetType: settingsContext.targetType,
			targetId: settingsContext.targetId,
			settings: settingsContext.values,
			block: settingsContext.block,
			fieldKey: settingsContext.fieldKey ?? 'customCss',
			value: completionContext.state.doc.toString(),
		};
		const entries = [...providers].sort((a, b) => a.priority - b.priority || a.seq - b.seq);
		const results = await Promise.all(entries.map(async ({ provider }) => {
			try {
				const options = await provider(providerContext);
				return Array.isArray(options) ? options.filter((option) => typeof option?.label === 'string') : [];
			} catch {
				return [];
			}
		}));
		const options = results.flat();
		if (options.length === 0) return null;

		return { from: match?.from ?? completionContext.pos, options };
	};
}

export function resetCssAutocompleteProvidersForTests() {
	providers.length = 0;
	sequence = 0;
}
