import { describe, expect, test } from 'bun:test';

const hadDocument = Object.prototype.hasOwnProperty.call(globalThis, 'document');
const hadElement = hadDocument && Object.prototype.hasOwnProperty.call(globalThis.document, 'documentElement');
if (!hadDocument) {
	globalThis.document = { documentElement: { style: {} } };
} else if (!globalThis.document.documentElement) {
	globalThis.document.documentElement = { style: {} };
}

const [{ css }, { indentRange, indentUnit }, { EditorState }] = await Promise.all([
	import('@codemirror/lang-css'),
	import('@codemirror/language'),
	import('@codemirror/state'),
]);

if (!hadDocument) {
	delete globalThis.document;
} else if (!hadElement) {
	delete globalThis.document.documentElement;
}

describe('Custom CSS indentation', () => {
	test('formats nested CSS blocks with two-space indentation', () => {
		const state = EditorState.create({
			doc: '.card {\ncolor: red;\n& .title {\nfont-weight: bold;\n}\n}',
			extensions: [css(), indentUnit.of('  ')],
		});

		const formatted = state.update({ changes: indentRange(state, 0, state.doc.length) }).state.doc.toString();

		expect(formatted).toBe('.card {\n  color: red;\n  & .title {\n    font-weight: bold;\n  }\n}');
	});
});
