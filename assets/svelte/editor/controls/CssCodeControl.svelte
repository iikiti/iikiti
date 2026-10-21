<script lang="ts">
	import { onMount } from 'svelte';
	import { autocompletion } from '@codemirror/autocomplete';
	import { indentWithTab } from '@codemirror/commands';
	import { css, cssCompletionSource } from '@codemirror/lang-css';
	import { bracketMatching, defaultHighlightStyle, indentOnInput, indentRange, indentUnit, syntaxHighlighting } from '@codemirror/language';
	import { Compartment, EditorState } from '@codemirror/state';
	import { oneDark } from '@codemirror/theme-one-dark';
	import { EditorView, drawSelection, highlightActiveLine, keymap, lineNumbers } from '@codemirror/view';
	import { get } from 'svelte/store';
	import { editorSettings, searchNode, selected, validateEditorCss } from '../state.js';
	import { createSettingsCssCompletionSource } from '../cssAutocomplete.js';
	import { styleTargetSelector } from '../styleValues.js';

	interface Props {
		field: Record<string, unknown>;
		value: unknown;
		onChange: (value: unknown) => void;
	}

	let { field, value, onChange }: Props = $props();
	let host: HTMLDivElement;
	let editor = $state<EditorView | null>(null);
	let syncing = false;
	let validationError = $state('');
	let validationUnavailable = $state(false);
	let validationSequence = 0;
	let validationTimer: ReturnType<typeof setTimeout> | null = null;
	let validationInput = '';

	const ariaLabel = $derived(String(field.label ?? 'Custom CSS'));
	const theme = new Compartment();

	function autocompleteContext() {
		const fieldKey = String(field.key ?? 'customCss');
		if (field.cssScope === 'block') {
			const blockId = get(selected);
			const block = blockId ? searchNode(blockId) : null;
			return {
				targetType: 'block',
				targetId: blockId,
				values: block?.style ?? {},
				block,
				fieldKey,
			};
		}

		return { ...get(editorSettings), fieldKey };
	}

	function codeMirrorTheme(dark: boolean) {
		const layoutTheme = EditorView.theme({
			'&': { minHeight: '260px' },
			'.cm-scroller': { minHeight: '260px', fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace' },
			'.cm-content': { padding: '8px 0' },
			'.cm-line': { padding: '0 8px' },
		});
		const lightTheme = EditorView.theme({
			'&': { backgroundColor: 'var(--ik-panel-bg, #f9f7f2)', color: 'var(--ik-panel-text, #3a3830)' },
			'.cm-gutters': {
				backgroundColor: 'var(--ik-panel-bg, #f9f7f2)',
				color: 'var(--ik-panel-text-muted, #7a7669)',
				borderRight: '1px solid var(--ik-panel-border, #ddd6cb)',
			},
		}, { dark });

		return dark ? [oneDark, layoutTheme] : [layoutTheme, lightTheme];
	}

	function scheduleValidation(source: string, updateCompiled = false) {
		validationInput = source;
		validationSequence++;
		if (null !== validationTimer) clearTimeout(validationTimer);
		if (updateCompiled) applyCssPreview(null);
		if ('' === source.trim()) {
			validationError = '';
			validationUnavailable = false;
			applyCssPreview(null);
			if (updateCompiled) onChange({ source, compiled: '' });
			return;
		}

		const requestSequence = validationSequence;
		validationTimer = setTimeout(async () => {
			try {
				const result = await validateEditorCss(source);
				if (requestSequence !== validationSequence) return;
				validationError = result.valid ? '' : result.error ?? 'CSS syntax is invalid.';
				validationUnavailable = false;
				applyCssPreview(result.valid ? result.compiled ?? null : null);
				if (updateCompiled) onChange({ source, compiled: result.valid ? result.compiled ?? '' : null });
			} catch {
				if (requestSequence !== validationSequence) return;
				validationError = '';
				validationUnavailable = true;
				applyCssPreview(null);
				if (updateCompiled) onChange({ source, compiled: null });
			}
		}, 350);
	}

	function applyCssPreview(compiled: string | null) {
		const context = autocompleteContext();
		let id: string;
		let scopeSelector: string;
		if (context.targetType === 'block' && context.block?.id) {
			id = `iikiti-custom-css-block-${context.block.id}`;
			scopeSelector = styleTargetSelector(context.block);
		} else if (['page', 'template'].includes(String(context.targetType))) {
			const targetId = Number(context.targetId);
			if (!Number.isInteger(targetId) || targetId < 1) return;
			id = `iikiti-custom-css-preview-${context.targetType}-${targetId}`;
			scopeSelector = `body.iikiti-${context.targetType}-id--${targetId}`;
		} else {
			return;
		}

		let style = document.getElementById(id) as HTMLStyleElement | null;
		if (!compiled) {
			style?.remove();
			return;
		}
		if (!style) {
			style = document.createElement('style');
			style.id = id;
			style.dataset.iikitiCustomCssPreview = String(context.targetType);
			document.head.append(style);
		}
		style.textContent = compiled.replaceAll('.iikiti-custom-css-scope-placeholder', scopeSelector);
	}

	onMount(() => {
		scheduleValidation(String(value ?? ''));
		const customCompletionSource = createSettingsCssCompletionSource(autocompleteContext);
		const isDark = () => document.documentElement.classList.contains('dark');
		const state = EditorState.create({
			doc: String(value ?? ''),
			extensions: [
				css(),
				lineNumbers(),
				highlightActiveLine(),
				drawSelection(),
				bracketMatching(),
				indentOnInput(),
				indentUnit.of('  '),
				syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
				theme.of(codeMirrorTheme(isDark())),
				keymap.of([indentWithTab]),
				autocompletion({ override: [cssCompletionSource, customCompletionSource] }),
				EditorView.contentAttributes.of({ 'aria-label': ariaLabel, 'aria-multiline': 'true' }),
				EditorView.updateListener.of((update) => {
					if (update.docChanged && !syncing) {
						const source = update.state.doc.toString();
						scheduleValidation(source, true);
						onChange(source);
					}
				}),
			],
		});
		editor = new EditorView({ state, parent: host });
		const themeObserver = new MutationObserver(() => {
			editor?.dispatch({ effects: theme.reconfigure(codeMirrorTheme(isDark())) });
		});
		themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

		return () => {
			validationSequence++;
			if (null !== validationTimer) clearTimeout(validationTimer);
			themeObserver.disconnect();
			editor?.destroy();
			editor = null;
		};
	});

	$effect(() => {
		const next = String(value ?? '');
		if (next !== validationInput) scheduleValidation(next);
		if (!editor || editor.state.doc.toString() === next) return;
		syncing = true;
		editor.dispatch({ changes: { from: 0, to: editor.state.doc.length, insert: next } });
		syncing = false;
	});

	function formatCss() {
		if (!editor) return;
		const { state } = editor;
		const changes = indentRange(state, 0, state.doc.length);
		editor.dispatch({ changes });
	}
</script>

<div class="css-code-control">
	<div class="css-code-control__toolbar">
		<button type="button" class="css-code-control__format" onclick={formatCss}>Format</button>
	</div>
	<div class="css-code-control__editor" bind:this={host}></div>
	{#if validationError}
		<div class="css-code-control__warning" role="alert">
			<strong>Invalid CSS. It will not be applied.</strong>
			<span>{validationError}</span>
			<a href="https://developer.mozilla.org/en-US/docs/Web/CSS" target="_blank" rel="noopener noreferrer">CSS syntax documentation</a>
		</div>
	{:else if validationUnavailable}
		<div class="css-code-control__warning" role="status">
			<span>CSS could not be validated and may not be applied.</span>
			<a href="https://developer.mozilla.org/en-US/docs/Web/CSS" target="_blank" rel="noopener noreferrer">CSS syntax documentation</a>
		</div>
	{/if}
</div>

<style>
	.css-code-control {
		border: 1px solid var(--ik-panel-border, #ddd6cb);
		border-radius: 0.375rem;
		background: var(--ik-panel-bg, #f9f7f2);
		color: var(--ik-panel-text, #3a3830);
		overflow: hidden;
	}

	.css-code-control__toolbar {
		display: flex;
		justify-content: flex-end;
		padding: 0.25rem 0.5rem;
		background: var(--ik-panel-bg, #f9f7f2);
		border-bottom: 1px solid var(--ik-panel-border, #ddd6cb);
	}

	.css-code-control__format {
		padding: 0.2rem 0.45rem;
		border-radius: 0.25rem;
		color: var(--ik-panel-text-muted, #7a7669);
		font-size: 0.75rem;
	}

	.css-code-control__format:hover,
	.css-code-control__format:focus-visible {
		background: color-mix(in srgb, var(--ik-panel-text, #3a3830) 10%, transparent);
		color: var(--ik-panel-text, #3a3830);
	}

	.css-code-control__editor :global(.cm-editor) {
		font-size: 0.8125rem;
		outline: none;
	}

	.css-code-control__warning {
		display: flex;
		flex-direction: column;
		gap: 0.25rem;
		padding: 0.5rem 0.625rem;
		border-top: 1px solid var(--ik-panel-border, #ddd6cb);
		background: color-mix(in srgb, var(--iikiti-danger, #b42318) 8%, var(--ik-panel-bg, #f9f7f2));
		color: var(--ik-panel-text, #3a3830);
		font-size: 0.75rem;
	}

	.css-code-control__warning strong { color: var(--iikiti-danger, #b42318); }
	.css-code-control__warning a { color: var(--ik-accent, #a6613c); text-decoration: underline; }
</style>
