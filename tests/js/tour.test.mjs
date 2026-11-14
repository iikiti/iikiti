import { test, expect, beforeEach, afterEach } from 'bun:test';
import {
	register,
	list,
	start,
	next,
	prev,
	jumpTo,
	end,
	on,
	getState,
	registerAction,
	highlight,
	resolveTarget,
} from '../../assets/js/iikiti/tour.js';

const originalDocument = globalThis.document;
const originalCSS = globalThis.CSS;
const originalHTMLElement = globalThis.HTMLElement;

class FakeElement {
	constructor() {
		const set = new Set();
		this.classList = {
			add: (c) => set.add(c),
			remove: (c) => set.delete(c),
			contains: (c) => set.has(c),
			toggle: (c, on) => (on ? set.add(c) : set.delete(c)),
		};
		this.dataset = {};
		this.scrollIntoView = () => {};
	}
	setAttribute(key, value) {
		this['attr_' + key] = value;
	}
	getAttribute(key) {
		return this['attr_' + key] ?? null;
	}
}

// The source uses `instanceof HTMLElement` for element targets; Bun has no DOM.
const fakeElements = new Map();

beforeEach(() => {
	globalThis.CSS = { escape: (value) => value };
	globalThis.HTMLElement = FakeElement;
	fakeElements.clear();
	globalThis.document = {
		createElement: () => new FakeElement(),
		querySelector: (sel) => {
			const match = /^\[data-tour="(.*)"\]$/.exec(sel);
			if (match) return fakeElements.get(match[1]) ?? null;
			return null;
		},
		querySelectorAll: () => [],
	};
});

afterEach(() => {
	end();
	globalThis.document = originalDocument;
	globalThis.CSS = originalCSS;
	globalThis.HTMLElement = originalHTMLElement;
});

test('register stores a tour definition', () => {
	register({ id: 't1', name: 'Tour 1', steps: [] });
	expect(list().find((tour) => tour.id === 't1').name).toBe('Tour 1');
});

test('register ignores tours without id or steps', () => {
	const before = list().length;
	register({ name: 'No ID' });
	register({ id: 't2', name: 'No Steps' });
	expect(list()).toHaveLength(before);
});

test('list returns all registered tours', () => {
	register({ id: 'a', name: 'A', steps: [] });
	register({ id: 'b', name: 'B', steps: [] });
	expect(list().map((t) => t.id)).toContain('a');
	expect(list().map((t) => t.id)).toContain('b');
});

test('start sets active tour and step', () => {
	register({
		id: 't1',
		name: 'T1',
		steps: [{ target: 'el1', title: 'One' }, { target: 'el2', title: 'Two' }],
	});
	start('t1');
	const state = getState();
	expect(state.tourId).toBe('t1');
	expect(state.stepIndex).toBe(0);
	expect(state.total).toBe(2);
	expect(state.step.title).toBe('One');
});

test('start clamps stepIndex to valid range', () => {
	register({ id: 't1', name: 'T1', steps: [{ target: 'a' }, { target: 'b' }] });
	start('t1', 5); // clamped to 1
	expect(getState().stepIndex).toBe(1);
	start('t1', -5); // clamped to 0
	expect(getState().stepIndex).toBe(0);
});

test('next advances to the next step', () => {
	register({
		id: 't1',
		name: 'T1',
		steps: [{ target: 'a' }, { target: 'b' }, { target: 'c' }],
	});
	start('t1');
	expect(getState().stepIndex).toBe(0);
	next();
	expect(getState().stepIndex).toBe(1);
	next();
	expect(getState().stepIndex).toBe(2);
	next(); // last step ends the tour
	expect(getState()).toBeNull();
});

test('prev goes to the previous step', () => {
	register({
		id: 't1',
		name: 'T1',
		steps: [{ target: 'a' }, { target: 'b' }, { target: 'c' }],
	});
	start('t1', 2);
	prev();
	expect(getState().stepIndex).toBe(1);
	prev();
	expect(getState().stepIndex).toBe(0);
	prev(); // already at 0, no-op
	expect(getState().stepIndex).toBe(0);
});

test('jumpTo goes to a specific step', () => {
	register({
		id: 't1',
		name: 'T1',
		steps: [{ target: 'a' }, { target: 'b' }, { target: 'c' }],
	});
	start('t1');
	jumpTo(2);
	expect(getState().stepIndex).toBe(2);
	jumpTo(99); // out of range, no-op
	expect(getState().stepIndex).toBe(2);
});

test('end clears the active tour', () => {
	register({ id: 't1', name: 'T1', steps: [{ target: 'a' }] });
	start('t1');
	expect(getState()).not.toBeNull();
	end();
	expect(getState()).toBeNull();
});

test('on receives state change notifications', () => {
	register({ id: 't1', name: 'T1', steps: [{ target: 'a' }] });
	const states = [];
	const unsub = on((state) => states.push(state));
	start('t1');
	const relevant = states.filter((state) => state);
	expect(relevant.length).toBeGreaterThan(0);
	expect(relevant[0].tourId).toBe('t1');
	unsub();
	next(); // ends tour, should not add more states
	const last = states[states.length - 1];
	expect(last).not.toBeNull();
});

test('registerAction adds an action handler', () => {
	const calls = [];
	registerAction('test-action', (action) => {
		calls.push(action.foo);
	});
	register({ id: 'actions', steps: [{ before: [{ type: 'test-action', foo: 'bar' }] }] });
	start('actions');
	expect(calls).toEqual(['bar']);
});

test('highlight toggles a class on the target element', () => {
	const el = new FakeElement();
	fakeElements.set('my-target', el);
	globalThis.document.querySelector = (sel) => (sel === '[data-tour="my-target"]' ? el : null);
	highlight('my-target', true);
	expect(el.classList.contains('iikiti-tour-spotlight')).toBe(true);
	highlight('my-target', false);
	expect(el.classList.contains('iikiti-tour-spotlight')).toBe(false);
});

test('resolveTarget finds element by data-tour attribute', () => {
	const el = new FakeElement();
	fakeElements.set('find-me', el);
	expect(resolveTarget('find-me')).toBe(el);
	expect(resolveTarget('nonexistent')).toBeNull();
	expect(resolveTarget(null)).toBeNull();
	const el2 = new FakeElement();
	expect(resolveTarget(el2)).toBe(el2);
});
