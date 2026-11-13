import { afterEach, expect, test } from 'bun:test';
import { BarsManager, normalizeIncludeInLayout, resolveBarMeasurement } from './bars.js';

const savedGlobals = Object.fromEntries([
	'document', 'window', 'HTMLElement', 'ResizeObserver', 'MutationObserver',
	'requestAnimationFrame', 'cancelAnimationFrame', 'getComputedStyle', 'matchMedia',
].map((key) => [key, globalThis[key]]));

class FakeStyle {
	constructor() { this.properties = new Map(); }
	setProperty(name, value) { this.properties.set(name, value); }
	getPropertyValue(name) { return this.properties.get(name) || ''; }
}

class FakeClassList {
	constructor() { this.values = new Set(); }
	add(...values) { values.forEach((value) => this.values.add(value)); }
	remove(...values) { values.forEach((value) => this.values.delete(value)); }
	contains(value) { return this.values.has(value); }
	toggle(value, force) {
		if (force === true || (force === undefined && !this.values.has(value))) this.values.add(value);
		else this.values.delete(value);
		return this.values.has(value);
	}
}

class FakeElement {
	constructor(rect = { x: 0, y: 0, top: 0, right: 320, bottom: 48, left: 0, width: 320, height: 48 }) {
		this.rect = { ...rect };
		this.dataset = {};
		this.style = {};
		this.classList = new FakeClassList();
		this.attributes = new Map();
		this.children = [];
		this.parentNode = null;
		this.listeners = new Map();
		this.inert = false;
		this.hidden = false;
	}
	get nextElementSibling() {
		if (!this.parentNode) return null;
		const index = this.parentNode.children.indexOf(this);
		return this.parentNode.children[index + 1] || null;
	}
	getAttribute(name) { return this.attributes.get(name) ?? null; }
	setAttribute(name, value) { this.attributes.set(name, String(value)); }
	removeAttribute(name) { this.attributes.delete(name); }
	appendChild(child) { return this.insertBefore(child, null); }
	insertBefore(child, reference) {
		if (child.parentNode) child.remove();
		const index = reference ? this.children.indexOf(reference) : this.children.length;
		this.children.splice(index < 0 ? this.children.length : index, 0, child);
		child.parentNode = this;
		return child;
	}
	remove() {
		if (!this.parentNode) return;
		const index = this.parentNode.children.indexOf(this);
		if (index >= 0) this.parentNode.children.splice(index, 1);
		this.parentNode = null;
	}
	getBoundingClientRect() { return { ...this.rect }; }
	addEventListener(name, fn) {
		const listeners = this.listeners.get(name) || new Set();
		listeners.add(fn);
		this.listeners.set(name, listeners);
	}
	removeEventListener(name, fn) { this.listeners.get(name)?.delete(fn); }
}

function installDom() {
	const body = new FakeElement();
	const documentElement = { style: new FakeStyle() };
	globalThis.HTMLElement = FakeElement;
	globalThis.document = {
		body,
		documentElement,
		createElement: () => new FakeElement({ x: 0, y: 0, top: 0, right: 0, bottom: 0, left: 0, width: 0, height: 0 }),
		querySelector: () => null,
		addEventListener() {},
	};
	globalThis.window = { innerHeight: 600, innerWidth: 800, scrollY: 0, addEventListener() {} };
	globalThis.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
	globalThis.MutationObserver = class { observe() {} disconnect() {} };
	globalThis.requestAnimationFrame = () => 1;
	globalThis.cancelAnimationFrame = () => {};
	globalThis.getComputedStyle = (el) => ({
		position: el.style.position || el.authoredPosition || 'static',
		display: el.style.display || 'block',
		visibility: el.style.visibility || 'visible',
		marginBlockStart: el.style.marginBlockStart || '0px',
		marginBlockEnd: el.style.marginBlockEnd || '0px',
		transitionDuration: el.style.transitionDuration || '0s',
		transitionDelay: el.style.transitionDelay || '0s',
	});
	globalThis.matchMedia = () => ({ matches: false });
	return { body, documentElement };
}

afterEach(() => {
	for (const [key, value] of Object.entries(savedGlobals)) {
		if (value === undefined) delete globalThis[key];
		else globalThis[key] = value;
	}
});

function bar(overrides = {}) {
	return {
		mode: 'stack',
		authoredOverlay: false,
		includeInLayout: 'auto',
		visible: true,
		lastMeasuredSize: { width: 0, height: 0 },
		...overrides,
	};
}

test('normalizes supported measurement policies', () => {
	expect(normalizeIncludeInLayout(undefined)).toBe('auto');
	expect(normalizeIncludeInLayout('include')).toBe('include');
	expect(normalizeIncludeInLayout('exclude')).toBe('exclude');
	expect(normalizeIncludeInLayout('invalid')).toBe('auto');
});

test('auto includes visible stack bars and excludes authored overlays', () => {
	const rect = { width: 240, height: 48 };
	expect(resolveBarMeasurement(bar(), rect).contributionSize).toEqual(rect);
	expect(resolveBarMeasurement(bar({ authoredOverlay: true }), rect).contributionSize)
		.toEqual({ width: 0, height: 0 });
	expect(resolveBarMeasurement(bar({ mode: 'absolute' }), rect).contributionSize)
		.toEqual({ width: 0, height: 0 });
});

test('forced inclusion uses live dimensions when visible and cached dimensions when hidden', () => {
	const registered = bar({
		mode: 'absolute',
		includeInLayout: 'include',
		lastMeasuredSize: { width: 280, height: 54 },
	});

	expect(resolveBarMeasurement(registered, { width: 300, height: 60 }).contributionSize)
		.toEqual({ width: 300, height: 60 });
	expect(resolveBarMeasurement(registered, { width: 0, height: 0 }, true)).toMatchObject({
		visible: false,
		included: true,
		lastMeasuredSize: { width: 300, height: 60 },
		contributionSize: { width: 300, height: 60 },
	});
});

test('auto hidden and explicitly excluded bars contribute zero', () => {
	const hidden = bar({ visible: false, lastMeasuredSize: { width: 100, height: 40 } });
	expect(resolveBarMeasurement(hidden, { width: 0, height: 0 }, true).contributionSize)
		.toEqual({ width: 0, height: 0 });

	const excluded = bar({ includeInLayout: 'exclude' });
	expect(resolveBarMeasurement(excluded, { width: 100, height: 40 }).contributionSize)
		.toEqual({ width: 0, height: 0 });
});

test('hidden forced inclusion with no prior measurable size contributes zero', () => {
	const hidden = bar({ includeInLayout: 'include', visible: false });
	expect(resolveBarMeasurement(hidden, { width: 0, height: 0 }, true).contributionSize)
		.toEqual({ width: 0, height: 0 });
});

test('manager excludes authored overlays by default and exposes them in filtered snapshots', () => {
	const { documentElement } = installDom();
	const manager = new BarsManager();
	const overlay = new FakeElement({ x: 0, y: 0, top: 0, right: 800, bottom: 32, left: 0, width: 800, height: 32 });
	overlay.authoredPosition = 'absolute';
	const registration = manager.register(overlay, { id: 'absolute-banner', side: 'top', type: 'banner' });
	manager._apply();

	expect(manager.getOffset('top')).toBe(0);
	expect(documentElement.style.getPropertyValue('--theme-bars-top')).toBe('0px');
	expect(documentElement.style.getPropertyValue('--iikiti-bars-top')).toBe('');
	expect(manager.list({ side: 'top' })).toEqual([]);
	const [snapshot] = manager.list({ type: 'banner', position: 'absolute', includeExcluded: true });
	expect(snapshot).toMatchObject({
		id: 'absolute-banner',
		authoredPosition: 'absolute',
		width: 800,
		height: 32,
		contributionSize: { width: 0, height: 0 },
	});
	expect(snapshot.element).toBe(overlay);

	registration.setIncluded(true);
	manager._apply();
	expect(manager.getOffset('top')).toBe(32);
	expect(documentElement.style.getPropertyValue('--theme-bars-top')).toBe('32px');
	manager.unregister(registration.id);
});

test('bar listeners receive state deltas and can unsubscribe', () => {
	installDom();
	const manager = new BarsManager();
	const el = new FakeElement();
	const registration = manager.register(el, { id: 'header', side: 'top', sticky: true });
	manager._apply();
	const changes = [];
	const unsubscribe = registration.onChange((change) => changes.push(change));
	registration.setIncluded('exclude');
	manager._apply();
	expect(changes.map(({ property }) => property)).toContain('policy');
	expect(changes.map(({ property }) => property)).toContain('included');
	expect(changes[0].element).toBe(el);

	const receivedBeforeUnsubscribe = changes.length;
	unsubscribe();
	registration.setIncluded('include');
	manager._apply();
	expect(changes).toHaveLength(receivedBeforeUnsubscribe);
	manager.unregister(registration.id);
});

test('forced hidden inclusion preserves the last measurable dimensions', () => {
	installDom();
	const manager = new BarsManager();
	const el = new FakeElement({ x: 0, y: 0, top: 0, right: 700, bottom: 44, left: 0, width: 700, height: 44 });
	const registration = manager.register(el, {
		id: 'fixed-reserve', side: 'top', mode: 'fixed', includeInLayout: 'include',
	});
	manager._apply();
	registration.setVisible(false);
	el.rect = { x: 0, y: 0, top: 0, right: 0, bottom: 0, left: 0, width: 0, height: 0 };
	manager._apply();

	expect(manager.getOffset('top')).toBe(44);
	expect(manager.list({ includeExcluded: true })[0]).toMatchObject({
		visible: false,
		width: 0,
		height: 0,
		lastMeasuredSize: { width: 700, height: 44 },
		contributionSize: { width: 700, height: 44 },
	});
	registration.destroy();
});

test('sticky handles emit stuck and unstuck transitions from sentinel geometry', () => {
	installDom();
	const manager = new BarsManager();
	const el = new FakeElement();
	const registration = manager.register(el, { id: 'sticky-top', side: 'top', sticky: true });
	manager._apply();
	const changes = [];
	registration.onChange((change) => changes.push(change));

	const record = manager._bars.get(registration.id);
	record.sentinel.rect.top = -2;
	manager._checkStuck();
	expect(manager.list({ includeExcluded: true })[0].stuck).toBe(true);
	record.sentinel.rect.top = 0;
	manager._checkStuck();
	expect(manager.list({ includeExcluded: true })[0].stuck).toBe(false);
	expect(changes.filter(({ property }) => property === 'stuck').map(({ current }) => current))
		.toEqual([true, false]);
	registration.destroy();
});

test('flow collapse releases and restores top-bar flow space without dimension animation', () => {
	installDom();
	globalThis.matchMedia = () => ({ matches: true });
	const manager = new BarsManager();
	const el = new FakeElement({ x: 0, y: 0, top: 0, right: 640, bottom: 50, left: 0, width: 640, height: 50 });
	const registration = manager.register(el, { id: 'animated-header', side: 'top', collapse: 'flow' });
	manager._apply();
	registration.setVisible(false);
	manager._apply();
	expect(el.style.marginBlockEnd).toBe('-50px');
	expect(el.style.height).toBeUndefined();
	expect(el.classList.contains('iikiti-hidden')).toBe(true);
	expect(manager.getOffset('top')).toBe(0);

	registration.setVisible(true);
	manager._apply();
	expect(el.style.marginBlockEnd).toBe('');
	expect(el.classList.contains('iikiti-hidden')).toBe(false);
	expect(manager.getOffset('top')).toBe(50);
	registration.destroy();
});

test('flow collapse compensates authored block margins without double-counting and restores on unregister', () => {
	installDom();
	globalThis.matchMedia = () => ({ matches: true });
	const manager = new BarsManager();
	const el = new FakeElement({ x: 0, y: 0, top: 0, right: 640, bottom: 50, left: 0, width: 640, height: 50 });
	el.style.marginBlockStart = '8px';
	el.style.marginBlockEnd = '12px';
	const registration = manager.register(el, { id: 'margined-bottom', side: 'bottom', collapse: 'flow' });
	manager._apply();

	registration.setVisible(false);
	manager._apply();
	// Overwrites marginBlockStart: -(height + marginBlockEnd) = -(50 + 12) = -62px
	expect(el.style.marginBlockStart).toBe('-62px');
	expect(el.style.marginBlockEnd).toBe('12px');
	expect(manager.getOffset('bottom')).toBe(0);

	registration.destroy();
	expect(el.style.marginBlockStart).toBe('8px');
	expect(el.style.marginBlockEnd).toBe('12px');
});

test('rails remain measurable despite manager-applied fixed positioning and release horizontal space when hidden', () => {
	const { documentElement } = installDom();
	const manager = new BarsManager();
	const rail = new FakeElement({ x: 0, y: 0, top: 0, right: 240, bottom: 600, left: 0, width: 240, height: 600 });
	const registration = manager.register(rail, { id: 'left-rail', side: 'left', size: 240, collapse: 'flow' });
	manager._apply();

	expect(rail.style.position).toBe('fixed');
	expect(manager.getOffset('left')).toBe(240);
	expect(documentElement.style.getPropertyValue('--theme-bars-left')).toBe('240px');

	// Re-applying layout after manager set position:fixed must NOT treat it as an authored overlay
	manager._apply();
	expect(manager.getOffset('left')).toBe(240);

	registration.setVisible(false);
	manager._apply();
	expect(manager.getOffset('left')).toBe(0);
	expect(documentElement.style.getPropertyValue('--theme-bars-left')).toBe('0px');

	registration.setVisible(true);
	manager._apply();
	expect(manager.getOffset('left')).toBe(240);
	expect(documentElement.style.getPropertyValue('--theme-bars-left')).toBe('240px');
	registration.destroy();
});

test('legacy list() summary, global onChange changes payload, unchanged relayout suppression, and rapid flow reversal', () => {
	installDom();
	const manager = new BarsManager();
	const globalEvents = [];
	const stopGlobal = manager.onChange((payload) => globalEvents.push(payload));

	const el = new FakeElement({ x: 0, y: 0, top: 0, right: 640, bottom: 48, left: 0, width: 640, height: 48 });
	const registration = manager.register(el, { id: 'top-bar', side: 'top', order: 1, sticky: true, collapse: 'flow' });
	expect(manager.list()).toEqual([
		{ id: 'top-bar', side: 'top', order: 1, sticky: true, resizable: false, mode: 'stack' },
	]);
	expect(manager.list('bottom')).toEqual([]);

	const barEvents = [];
	registration.onChange((change) => barEvents.push(change));
	manager._apply();
	expect(globalEvents.at(-1).changes.map((c) => c.property)).toContain('registered');
	const countAfterFirstApply = barEvents.length;

	// Unchanged relayout must not emit duplicate per-bar events
	manager._apply();
	expect(barEvents).toHaveLength(countAfterFirstApply);
	expect(globalEvents.at(-1).changes).toEqual([]);

	// Rapid hide then show before transition completes restores flow and avoids hidden class
	registration.setVisible(false);
	expect(el.style.marginBlockEnd).toBe('-48px');
	expect(el.classList.contains('iikiti-hidden')).toBe(false);
	registration.setVisible(true);
	expect(el.style.marginBlockEnd).toBe('');
	expect(el.getAttribute('aria-hidden')).toBe('false');
	expect(el.inert).toBe(false);

	registration.destroy();
	manager._apply();
	expect(globalEvents.at(-1).changes.find((c) => c.property === 'unregistered')?.bar).toBeNull();
	stopGlobal();
});

test('re-registering an auto-wired bar upgrades frameworkManaged and collapse behavior', () => {
	installDom();
	const manager = new BarsManager();
	const el = new FakeElement({ x: 0, y: 0, top: 0, right: 800, bottom: 48, left: 0, width: 800, height: 48 });
	el.dataset.iikitiBar = 'top';
	const autoReg = manager.register(el);
	expect(autoReg.flowCollapse).toBe(false);

	const upgraded = manager.register(el, {
		side: 'top',
		type: 'header',
		collapse: 'auto',
		frameworkManaged: true,
		sticky: true,
	});
	expect(upgraded.id).toBe(autoReg.id);
	expect(upgraded.flowCollapse).toBe(true);
	expect(manager.list({ includeExcluded: true })[0]).toMatchObject({
		type: 'header',
		sticky: true,
	});
	upgraded.destroy();
});
