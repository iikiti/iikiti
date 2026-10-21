import { describe, expect, test } from 'bun:test';
import { css } from '@codemirror/lang-css';
import { indentRange, indentUnit } from '@codemirror/language';
import { EditorState } from '@codemirror/state';

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
