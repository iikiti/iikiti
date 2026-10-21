import { beforeEach, describe, expect, test } from 'bun:test';
import {
	createSettingsCssCompletionSource,
	registerCssAutocompleteProvider,
	resetCssAutocompleteProvidersForTests,
} from './cssAutocomplete.js';

beforeEach(() => resetCssAutocompleteProvidersForTests());

describe('settings CSS autocomplete providers', () => {
	test('provides scoped CSS suggestions in priority and registration order', async () => {
		registerCssAutocompleteProvider(async ({ targetType, fieldKey }) => [{ label: `${targetType}-${fieldKey}` }], { priority: 10 });
		registerCssAutocompleteProvider(() => [{ label: 'background-color' }]);
		const source = createSettingsCssCompletionSource(() => ({ targetType: 'page', targetId: 42, values: { name: 'Home' } }));
		const completionContext = {};
		completionContext.explicit = false;
		completionContext.pos = 8;
		completionContext.state = { doc: { toString: () => 'css' } };
		completionContext.matchBefore = () => ({ from: 10, to: 12 });

		expect(await source(completionContext)).toEqual({
			from: 10,
			options: [{ label: 'background-color' }, { label: 'page-customCss' }],
		});
	});

	test('allows explicit completion at an empty position and isolates provider errors', async () => {
		registerCssAutocompleteProvider(() => { throw new Error('plugin failure'); });
		registerCssAutocompleteProvider(() => [{ label: 'display' }, { invalid: true }]);
		const source = createSettingsCssCompletionSource(() => ({ targetType: 'template', targetId: 5, values: {} }));

		expect(await source({
			explicit: true,
			pos: 3,
			state: { doc: { toString: () => '' } },
			matchBefore: () => null,
		})).toEqual({ from: 3, options: [{ label: 'display' }] });
	});
});
