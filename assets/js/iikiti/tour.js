/**
 * iikiti tour / spotlight framework.
 *
 * Shared by the front-end editor and the admin SPA (and available to plugins
 * via `window.iikiti.tour`). A tour is a sequence of steps; each step targets
 * an element (`data-tour="<id>"` or a CSS selector), shows a popover above /
 * beside it, optionally spotlights it, and can run actions (e.g. switch the
 * editor settings sidebar tab).
 *
 *   iikiti.tour.register({
 *     id: 'intro',
 *     name: 'Intro',
 *     steps: [
 *       { target: 'toolbar.layers', placement: 'bottom', title: 'Layers',
 *         content: 'Open the structure of this region.',
 *         before: [{ type: 'sidebar.tab', value: 'element' }] },
 *     ],
 *   });
 *   iikiti.tour.start('intro');
 */
import { events } from './events.js';

const HIGHLIGHT_CLASS = 'iikiti-tour-spotlight';

/** @type {Map<string, any>} */
const tours = new Map();
/** @type {Map<string, (action:any, step:any)=>void>} */
const actions = new Map();
/** @type {Set<(state:any)=>void>} */
const listeners = new Set();

/** @type {{tourId:string|null, stepIndex:number}|null} */
let active = null;

/**
 * @param {string|Element|null} target
 * @returns {HTMLElement|null}
 */
export function resolveTarget(target) {
	if (!target) return null;
	if (typeof target !== 'string') {
		return target instanceof HTMLElement ? target : null;
	}
	try {
		if (/^[#.\[]/.test(target)) return /** @type {HTMLElement|null} */ (document.querySelector(target));
		return /** @type {HTMLElement|null} */ (document.querySelector(`[data-tour="${CSS.escape(target)}"]`));
	} catch {
		return null;
	}
}

/**
 * @param {string|Element|null} target
 * @param {boolean} on
 */
export function highlight(target, on) {
	const el = resolveTarget(target);
	if (!el) return;
	el.classList.toggle(HIGHLIGHT_CLASS, on);
}

function clearHighlights() {
	document.querySelectorAll(`.${HIGHLIGHT_CLASS}`).forEach((el) => el.classList.remove(HIGHLIGHT_CLASS));
}

/** @returns {any} */
function currentStep() {
	if (!active) return null;
	const tour = tours.get(active.tourId);
	return tour?.steps?.[active.stepIndex] ?? null;
}

/** @returns {{tourId:string|null, stepIndex:number, total:number, step:any}|null} */
export function getState() {
	if (!active) return null;
	const tour = tours.get(active.tourId);
	return {
		tourId: active.tourId,
		stepIndex: active.stepIndex,
		total: tour?.steps?.length ?? 0,
		step: currentStep(),
	};
}

function notify() {
	const state = getState();
	for (const fn of [...listeners]) fn(state);
}

/**
 * @param {(state:any)=>void} fn
 * @returns {()=>void} unsubscribe
 */
export function on(fn) {
	listeners.add(fn);
	fn(getState());
	return () => listeners.delete(fn);
}

/**
 * @param {any} tourDef
 */
export function register(tourDef) {
	if (!tourDef?.id || !Array.isArray(tourDef.steps)) return;
	tours.set(tourDef.id, tourDef);
}

/** @returns {any[]} */
export function list() {
	return [...tours.values()];
}

/**
 * @param {string} type
 * @param {(action:any, step:any)=>void} handler
 */
export function registerAction(type, handler) {
	actions.set(type, handler);
}

function runActions(list, step) {
	for (const action of list ?? []) {
		const handler = actions.get(action?.type);
		if (handler) {
			try {
				handler(action, step);
			} catch {
				/* a broken action handler must not abort the tour */
			}
		}
	}
}

function enterStep() {
	const step = currentStep();
	if (!step) return;
	runActions(step.before, step);
	if (step.spotlight !== false) highlight(step.target, true);
	if (step.scroll !== false) {
		resolveTarget(step.target)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}
	notify();
}

function leaveStep() {
	const step = currentStep();
	if (!step) return;
	if (step.spotlight !== false) highlight(step.target, false);
	runActions(step.after, step);
}

/**
 * @param {string} id
 * @param {number} [stepIndex]
 */
export function start(id, stepIndex = 0) {
	const tour = tours.get(id);
	if (!tour?.steps?.length) return;
	if (active) leaveStep();
	active = { tourId: id, stepIndex: Math.max(0, Math.min(stepIndex, tour.steps.length - 1)) };
	enterStep();
	events.emit('iikiti:tour:start', { id });
}

export function next() {
	if (!active) return;
	const total = tours.get(active.tourId)?.steps?.length ?? 0;
	if (active.stepIndex >= total - 1) {
		end();
		return;
	}
	leaveStep();
	active.stepIndex += 1;
	enterStep();
}

export function prev() {
	if (!active || active.stepIndex <= 0) return;
	leaveStep();
	active.stepIndex -= 1;
	enterStep();
}

/** @param {number} index */
export function jumpTo(index) {
	if (!active) return;
	const total = tours.get(active.tourId)?.steps?.length ?? 0;
	if (index < 0 || index >= total) return;
	leaveStep();
	active.stepIndex = index;
	enterStep();
}

export function end() {
	if (!active) return;
	leaveStep();
	const id = active.tourId;
	active = null;
	clearHighlights();
	notify();
	events.emit('iikiti:tour:end', { id });
}

/** Install the built-in actions and expose `window.iikiti.tour`. */
export function installTour() {
	registerAction('highlight', (action) => highlight(action.target, action.on !== false));
	registerAction('scrollIntoView', (action) => {
		resolveTarget(action.target)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
	});
	registerAction('emit', (action) => {
		window.dispatchEvent(new CustomEvent(action.name ?? 'iikiti:tour:action', { detail: action }));
	});

	if (typeof window === 'undefined') return;
	const w = /** @type {any} */ (window);
	w.iikiti = w.iikiti ?? {};
	w.iikiti.tour = {
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
	};
}

export const tour = {
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
};
