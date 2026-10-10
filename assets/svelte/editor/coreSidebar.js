/**
 * Core sidebar registration.
 *
 * Registers the built-in field controls and the Content / Element / Style
 * sections through the same public API plugins use. Also installs
 * `window.iikiti.editor.sidebar`.
 */
import { registerFieldControl, registerFieldDecorator, registerSection, installSidebarApi } from './extensions.js';
import TextControl from './controls/TextControl.svelte';
import TextareaControl from './controls/TextareaControl.svelte';
import SelectControl from './controls/SelectControl.svelte';
import ToggleControl from './controls/ToggleControl.svelte';
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
		if (field.type !== 'repeater') return fieldNode(field, 'content');
		const key = String(field.key);
		return {
			kind: 'repeater',
			id: key,
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
	const fields = (ctx.schema?.elementFields ?? []).map((f) => fieldNode(f, 'element'));
	return [...fields, attributesGroup(ctx)];
}

/** @param {any} ctx */
function buildStyle(ctx) {
	return (ctx.schema?.styleFields ?? []).map((f) => fieldNode(f, 'style'));
}

export function registerCoreSidebar() {
	if (registered) return;
	registered = true;

	installSidebarApi();

	registerFieldControl('text', TextControl);
	registerFieldControl('url', TextControl);
	registerFieldControl('color', TextControl);
	registerFieldControl('media', TextControl);
	registerFieldControl('filters', TextControl);
	registerFieldControl('spacing', TextControl);
	registerFieldControl('number', TextControl);
	registerFieldControl('textarea', TextareaControl);
	registerFieldControl('richtext', TextareaControl);
	registerFieldControl('select', SelectControl);
	registerFieldControl('align', SelectControl);
	registerFieldControl('toggle', ToggleControl);

	registerSection({ id: 'content', label: 'Content', icon: 'type', order: 10, build: buildContent });
	registerSection({ id: 'element', label: 'Element', icon: 'cursor', order: 20, build: buildElement });
	registerSection({ id: 'style', label: 'Style', icon: 'palette', order: 30, build: buildStyle });

	// Core field decorator: bind sidebar fields to `query` result fields (the
	// same public API plugins use for their own decorators).
	registerFieldDecorator(queryBindingDecorator);
}
