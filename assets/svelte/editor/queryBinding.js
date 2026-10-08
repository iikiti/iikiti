/**
 * Core "query binding" field decorator.
 *
 * Applies to content fields of a block that sits inside a `query` block (the
 * block itself excluded). Style/element fields are not bindable: bindings are
 * resolved against the block's content map only. Renders a database-icon trigger
 * (see BindingTrigger.svelte) in the field's corner.
 */
import { queryAncestor } from './state';
import BindingTrigger from './BindingTrigger.svelte';

export const queryBindingDecorator = {
	id: 'query-binding',
	/**
	 * @param {any} ctx sidebar context ({ node, schema, blockTypes, update })
	 * @param {any} fieldNode the field node being decorated (`path` = content|element|style)
	 */
	applies: (ctx, fieldNode) => {
		if (fieldNode?.path !== 'content') return false;
		return Boolean(queryAncestor(ctx?.node));
	},
	component: BindingTrigger,
};
