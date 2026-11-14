import { test, expect } from 'bun:test';
import { events } from '../../assets/js/iikiti/events.js';

test('emit calls all listeners for a name', () => {
	const calls = [];
	events.on('test', (p) => calls.push(p));
	events.on('test', (p) => calls.push(p.toUpperCase()));
	events.emit('test', 'hello');
	expect(calls).toEqual(['hello', 'HELLO']);
});

test('emit with no listeners does not throw', () => {
	expect(() => events.emit('nonexistent', 'x')).not.toThrow();
});

test('off removes a specific listener', () => {
	const calls = [];
	const fn = (p) => calls.push(p);
	events.on('test', fn);
	events.emit('test', 'a');
	expect(calls).toEqual(['a']);
	events.off('test', fn);
	events.emit('test', 'b');
	expect(calls).toEqual(['a']);
});

test('off with no fn removes all listeners for a name', () => {
	const calls = [];
	events.on('test', () => calls.push(1));
	events.on('test', () => calls.push(2));
	events.off('test');
	events.emit('test', 'x');
	expect(calls).toEqual([]);
});

test('on returns an unsubscribe function', () => {
	const calls = [];
	const unsub = events.on('test', (p) => calls.push(p));
	events.emit('test', 'a');
	expect(calls).toEqual(['a']);
	unsub();
	events.emit('test', 'b');
	expect(calls).toEqual(['a']);
});

test('listeners are isolated per event name', () => {
	const callsA = [];
	const callsB = [];
	events.on('name-a', () => callsA.push(1));
	events.on('name-b', () => callsB.push(1));
	events.emit('name-a', 'x');
	expect(callsA).toEqual([1]);
	expect(callsB).toEqual([]);
});
