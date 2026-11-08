/**
 * Core sidebar registration.
 *
 * Registers the built-in field controls and the Content / Element / Style
 * sections through the same public API plugins use. Also installs
 * `window.iikiti.editor.sidebar`.
 */
import { registerFieldControl, registerFieldDecorator, registerGroup, registerSection, registerSettingsSection, installSidebarApi } from './extensions.js';
import TextControl from './controls/TextControl.svelte';
import TextareaControl from './controls/TextareaControl.svelte';
import SelectControl from './controls/SelectControl.svelte';
import ToggleControl from './controls/ToggleControl.svelte';
import CssLengthControl from './controls/CssLengthControl.svelte';
import ColorControl from './controls/ColorControl.svelte';
import CssCodeControl from './controls/CssCodeControl.svelte';
import { queryBindingDecorator } from './queryBinding.js';

let registered = false;

/**
 * @param {Record<string, unknown>} field
 * @param {'content'|'element'|'style'} path
 */
function fieldNode(field, path) {
	return { kind: 'field', field, path };
}

/**
 * Built-in "Attributes" group: a header plus a repeater of name/value rows that
 * render arbitrary HTML attributes onto the block element.
 *
 * @param {any} ctx
 */
function attributesGroup(ctx) {
	const items = Array.isArray(ctx.node?.element?.attributes) ? ctx.node.element.attributes : [];
	return {
		kind: 'group',
		id: 'attributes',
		order: 20,
		label: 'Attributes',
		header: 'Attributes',
		nodes: [
			{
				kind: 'repeater',
				id: 'attributes',
				label: 'Attributes',
				fields: [
					{ key: 'name', label: 'Name', type: 'text', placeholder: 'data-foo' },
					{ key: 'value', label: 'Value', type: 'text', placeholder: 'value' },
				],
				items,
				itemLabel: 'attribute',
				addLabel: 'Add attribute',
				onChange: (next) =>
					ctx.update({ element: { ...(ctx.node?.element ?? {}), attributes: next } }),
			},
		],
	};
}

/** @param {any} ctx */
function buildContent(ctx) {
	return (ctx.schema?.contentFields ?? []).map((field) => {
		if (field.type !== 'repeater') return fieldNode({ ...field, group: field.group ?? 'content' }, 'content');
		const key = String(field.key);
		return {
			kind: 'repeater',
			id: key,
			group: field.group ?? 'content',
			order: field.order,
			label: field.label,
			showLabel: true,
			fields: field.fields ?? [],
			items: Array.isArray(ctx.node?.content?.[key]) ? ctx.node.content[key] : [],
			itemLabel: field.itemLabel ?? 'item',
			addLabel: field.addLabel ?? 'Add',
			onChange: (items) => ctx.update({ content: { ...(ctx.node?.content ?? {}), [key]: items } }),
		};
	});
}

/** @param {any} ctx */
function buildElement(ctx) {
	const fields = (ctx.schema?.elementFields ?? []).map((f) => fieldNode({ ...f, group: f.group ?? 'element' }, 'element'));
	return [...fields, attributesGroup(ctx)];
}

/** @param {any} ctx */
function buildStyle(ctx) {
	return [
		...(ctx.schema?.styleFields ?? []).map((f) => fieldNode(f, 'style')),
		fieldNode({
			key: 'customCss',
			label: 'Custom CSS',
			type: 'css',
			group: 'advanced',
			order: 10,
			cssScope: 'block',
		}, 'style'),
	];
}

/** @param {any} ctx */
function buildRendering(ctx) {
	const selectNode = (key, label, options, get, set) => ({
		kind: 'field',
		path: 'rendering',
		field: { key, label, type: 'select', options },
		get,
		set,
	});
	const nodes = [selectNode(
		'renderMode',
		'Async rendering',
		[
			{ value: 'default', label: 'Default' },
			{ value: 'always', label: ctx.insideQuery ? 'Always async (unavailable in query rows)' : 'Always async', ...(ctx.insideQuery ? { disabled: true } : {}) },
			{ value: 'never', label: 'Never async' },
		],
		(context) => context.node?.renderMode ?? 'default',
		(context, value) => context.update({ renderMode: ['default', 'always', 'never'].includes(value) ? value : 'default' }),
	)];

	if (!ctx.isAsyncFragment) return nodes;

	const cacheModes = [
		{ value: 'none', label: 'None' },
		{ value: 'global', label: 'All visitors (this site)' },
		{ value: 'user_group', label: 'Selected user group' },
	];
	nodes.push(selectNode(
		'cacheMode',
		'Rendered output cache',
		cacheModes,
		(context) => context.node?.cacheMode ?? 'none',
		(context, value) => {
			const cacheMode = ['none', 'global', 'user_group'].includes(value) ? value : 'none';
			context.update({ cacheMode, ...(cacheMode === 'user_group' ? {} : { cacheGroupId: null }) });
		},
	));

	if ((ctx.node?.cacheMode ?? 'none') === 'user_group') {
		const groups = Array.isArray(ctx.config?.cacheGroups) ? ctx.config.cacheGroups : [];
		const options = [
			{ value: '', label: groups.length ? 'Select a user group' : 'No user groups available' },
			...groups.map((group) => ({ value: String(group.id), label: String(group.label) })),
		];
		nodes.push(selectNode(
			'cacheGroupId',
			'Cache-sharing group',
			options,
			(context) => context.node?.cacheGroupId ?? '',
			(context, value) => context.update({ cacheGroupId: String(value ?? '') }),
		));
	}

	return nodes;
}

export function registerCoreSidebar() {
	if (registered) return;
	registered = true;

	installSidebarApi();

	registerFieldControl('text', TextControl);
	registerFieldControl('url', TextControl);
	registerFieldControl('color', ColorControl);
	registerFieldControl('cssLength', CssLengthControl);
	registerFieldControl('media', TextControl);
	registerFieldControl('filters', TextControl);
	registerFieldControl('spacing', TextControl);
	registerFieldControl('number', TextControl);
	registerFieldControl('textarea', TextareaControl);
	registerFieldControl('richtext', TextareaControl);
	registerFieldControl('css', CssCodeControl);
	registerFieldControl('select', SelectControl);
	registerFieldControl('align', SelectControl);
	registerFieldControl('toggle', ToggleControl);

	registerGroup('content', { id: 'content', label: 'Content', order: 10 });
	registerGroup('element', { id: 'element', label: 'Element', order: 10 });
	registerGroup('element', { id: 'attributes', label: 'Attributes', order: 20 });
	registerGroup('style', { id: 'layout', label: 'Layout', order: 10 });
	registerGroup('style', { id: 'size', label: 'Size', order: 20 });
	registerGroup('style', { id: 'spacing', label: 'Spacing', order: 30 });
	registerGroup('style', { id: 'typography', label: 'Typography', order: 40 });
	registerGroup('style', { id: 'background', label: 'Background', order: 50 });
	registerGroup('style', { id: 'borders', label: 'Borders', order: 60 });
	registerGroup('style', { id: 'effects', label: 'Effects', order: 70 });
	registerGroup('style', { id: 'position', label: 'Position', order: 80 });
	registerGroup('style', { id: 'advanced', label: 'Advanced', order: 90 });
	registerGroup('style', { id: 'general', label: 'General', order: 1000 });
	registerGroup('advanced', { id: 'style', label: 'Style', order: 10 });

	registerSection({ id: 'content', label: 'Content', icon: 'type', order: 10, build: buildContent });
	registerSection({ id: 'element', label: 'Element', icon: 'cursor', order: 20, build: buildElement });
	registerSection({ id: 'style', label: 'Style', icon: 'palette', order: 30, build: buildStyle });
	registerSection({ id: 'rendering', label: 'Rendering', icon: 'zap', order: 40, build: buildRendering });
	registerSettingsSection({
		id: 'general', label: 'General', order: 10,
		build: () => [{ kind: 'field', path: 'settings', field: { key: 'name', label: 'Name', type: 'text', order: 10 } }],
	});
	registerSettingsSection({
		id: 'advanced', label: 'Advanced', order: 20,
		build: () => [{ kind: 'field', path: 'settings', field: { key: 'customCss', label: 'Custom CSS', type: 'css', group: 'style', order: 10 } }],
	});

	// Core field decorator: bind sidebar fields to `query` result fields (the
	// same public API plugins use for their own decorators).
	registerFieldDecorator(queryBindingDecorator);
}
