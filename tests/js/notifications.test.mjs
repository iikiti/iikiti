import { test, expect, beforeEach, afterEach } from 'bun:test';
import { notifications, settings } from '../../assets/js/iikiti/notifications.js';

let originalSetTimeout;
let timerCalls;

beforeEach(() => {
	timerCalls = [];
	originalSetTimeout = globalThis.setTimeout;
	globalThis.setTimeout = (cb, delay) => {
		timerCalls.push({ cb, delay });
		return { id: timerCalls.length };
	};
	settings.autoDismiss = true;
	settings.durationSeconds = 10;
	settings.position = 'bottom-right';
});

afterEach(() => {
	globalThis.setTimeout = originalSetTimeout;
});

test('notify creates a notification with defaults', () => {
	const id = notifications.notify({ message: 'Hello world' });
	expect(typeof id).toBe('string');
	expect(id.startsWith('iikiti-')).toBe(true);
	expect(timerCalls.length).toBe(1);
	expect(timerCalls[0].delay).toBe(10000);
});

test('notify uses custom type and duration', () => {
	const id = notifications.notify({ message: 'Error', type: 'error', duration: 30 });
	expect(timerCalls[0].delay).toBe(30000);
});

test('notify with autoDismiss off does not schedule a timer', () => {
	settings.autoDismiss = false;
	notifications.notify({ message: 'No timer' });
	expect(timerCalls.length).toBe(0);
});

test('dismiss marks a notification as dismissed', () => {
	const id = notifications.notify({ message: 'Dismiss me' });
	notifications.dismiss(id);
	notifications.dismiss(id); // idempotent
});

test('dismiss with unknown id does not throw', () => {
	expect(() => notifications.dismiss('nope')).not.toThrow();
});

test('on receives notify events', () => {
	const received = [];
	const unsub = notifications.on((evt) => received.push(evt));
	notifications.notify({ message: 'One' });
	expect(received.length).toBe(1);
	expect(received[0].kind).toBe('notify');
	expect(received[0].notification.message).toBe('One');
	unsub();
	notifications.notify({ message: 'Two' });
	expect(received.length).toBe(1);
});

test('on receives dismiss events', () => {
	const received = [];
	notifications.on((evt) => received.push(evt));
	const id = notifications.notify({ message: 'Dismiss' });
	notifications.dismiss(id);
	expect(received.length).toBe(2);
	expect(received[1].kind).toBe('dismiss');
	expect(received[1].id).toBe(id);
});

test('timer callback dismisses the notification', () => {
	const id = notifications.notify({ message: 'Auto-dismiss', duration: 5 });
	expect(timerCalls.length).toBe(1);
	timerCalls[0].cb();
	// After auto-dismiss, a manual dismiss should be idempotent
	notifications.dismiss(id);
});
