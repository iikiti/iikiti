import { test, expect, beforeEach } from 'bun:test';
import {
	registerBlockAction,
	unregisterBlockAction,
	patchBlockActions,
	resolveBlockActions,
} from '../../assets/svelte/editor/blockActions.js';

const block = (type) => ({ id: 'b1', type, children: [] });
const nodeCtx = (type) => ({ node: block(type), parentId: null, regionId: 'main', index: 0 });
const emptyCtx = { node: null, parentId: null, regionId: 'main', index: null };

// The registry is module-level; clear what each test adds.
const ids = [];
beforeEach(() => {
	for (const id of ids.splice(0)) unregisterBlockAction(id);
});
function register(action) {
	ids.push(action.id);
	registerBlockAction(action);
}

test('a general action (no blockTypes) shows for every block', () => {
	register({ id: 'g-test', label: 'General', run() {} });
	const labels = resolveBlockActions(nodeCtx('heading')).map((i) => i.id);
	expect(labels).toContain('g-test');
	expect(resolveBlockActions(nodeCtx('container')).map((i) => i.id)).toContain('g-test');
});

test('a type-scoped action only shows for its block types', () => {
	register({ id: 'scoped-test', label: 'Scoped', blockTypes: ['image'], run() {} });
	expect(resolveBlockActions(nodeCtx('image')).map((i) => i.id)).toContain('scoped-test');
	expect(resolveBlockActions(nodeCtx('heading')).map((i) => i.id)).not.toContain('scoped-test');
});

test('type-scoped actions never appear on the empty area', () => {
	register({ id: 'scoped-empty', label: 'Scoped', blockTypes: ['image'], run() {} });
	expect(resolveBlockActions(emptyCtx).map((i) => i.id)).not.toContain('scoped-empty');
});

test('applies() can hide an action per context', () => {
	register({ id: 'cond-test', label: 'Cond', applies: (ctx) => ctx.node?.type === 'text', run() {} });
	expect(resolveBlockActions(nodeCtx('text')).map((i) => i.id)).toContain('cond-test');
	expect(resolveBlockActions(nodeCtx('heading')).map((i) => i.id)).not.toContain('cond-test');
});

test('a throwing applies() hides the action instead of breaking the menu', () => {
	register({ id: 'throw-test', label: 'T', applies() { throw new Error('boom'); }, run() {} });
	expect(() => resolveBlockActions(nodeCtx('heading'))).not.toThrow();
	expect(resolveBlockActions(nodeCtx('heading')).map((i) => i.id)).not.toContain('throw-test');
});

test('actions are ordered by order then registration', () => {
	register({ id: 'late-test', label: 'L', order: 50, run() {} });
	register({ id: 'early-test', label: 'E', order: 1, run() {} });
	const orderedIds = resolveBlockActions(nodeCtx('heading')).map((i) => i.id);
	expect(orderedIds.indexOf('early-test')).toBeLessThan(orderedIds.indexOf('late-test'));
});

test('re-registering an id replaces the action', () => {
	register({ id: 'dup-test', label: 'First', run() {} });
	register({ id: 'dup-test', label: 'Second', run() {} });
	const matches = resolveBlockActions(nodeCtx('heading')).filter((i) => i.id === 'dup-test');
	expect(matches.length).toBe(1);
	expect(matches[0].label).toBe('Second');
});

test('patchBlockActions can remove and reorder items', () => {
	register({ id: 'removable-test', label: 'R', run() {} });
	patchBlockActions((items) => items.filter((i) => i.id !== 'removable-test'));
	expect(resolveBlockActions(nodeCtx('heading')).map((i) => i.id)).not.toContain('removable-test');
});

test('a throwing patch leaves the items unchanged', () => {
	register({ id: 'keep-test', label: 'K', run() {} });
	patchBlockActions(() => { throw new Error('bad patch'); });
	expect(resolveBlockActions(nodeCtx('heading')).map((i) => i.id)).toContain('keep-test');
});

test('onSelect runs the action with the context and swallows its errors', () => {
	let received = null;
	register({ id: 'run-test', label: 'Run', run(ctx) { received = ctx; } });
	const item = resolveBlockActions(nodeCtx('heading')).find((i) => i.id === 'run-test');
	expect(() => item.onSelect()).not.toThrow();
	expect(received.node.type).toBe('heading');
});

test('a throwing run() does not escape onSelect', () => {
	register({ id: 'boom-run-test', label: 'B', run() { throw new Error('nope'); } });
	const item = resolveBlockActions(nodeCtx('heading')).find((i) => i.id === 'boom-run-test');
	expect(() => item.onSelect()).not.toThrow();
});
