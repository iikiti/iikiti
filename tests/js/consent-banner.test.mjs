import { test, expect, mock } from 'bun:test';

const bannerSveltePath = new URL('../../plugins/consent-management/assets/svelte/consent/ConsentBanner.svelte', import.meta.url).pathname;
mock.module(bannerSveltePath, () => ({ default: function MockConsentBanner() {} }));

const { createBannerMounter, getInitialBannerCategories } = await import('../../plugins/consent-management/assets/consent-banner.js');

test('getInitialBannerCategories prefers active non-stale consent state over config defaults', () => {
	const documentRef = {
		getElementById: (id) => id === 'iikiti-consent-config'
			? { textContent: JSON.stringify({ default_categories: { necessary: true, analytics: false, marketing: false } }) }
			: null,
	};
	const windowRef = {
		iikiti: {
			consent: {
				getState: () => ({
					hasConsented: true,
					stale: false,
					categories: { necessary: true, analytics: true, marketing: false },
				}),
			},
		},
	};

	expect(getInitialBannerCategories(windowRef, documentRef)).toEqual({
		necessary: true,
		analytics: true,
		marketing: false,
	});
});

test('getInitialBannerCategories falls back to configured defaults when unconsented or stale', () => {
	const documentRef = {
		getElementById: (id) => id === 'iikiti-consent-config'
			? { textContent: JSON.stringify({ default_categories: { necessary: true, analytics: false, marketing: true } }) }
			: null,
	};
	const staleWindow = {
		iikiti: {
			consent: {
				getState: () => ({
					hasConsented: true,
					stale: true,
					categories: { necessary: true, analytics: true, marketing: false },
				}),
			},
		},
	};

	expect(getInitialBannerCategories(staleWindow, documentRef)).toEqual({
		necessary: true,
		analytics: false,
		marketing: true,
	});
});

test('createBannerMounter uses Svelte 5 unmount() when remounting the banner', () => {
	const mountedInstances = [];
	const unmountedInstances = [];
	let replaceChildrenCalls = 0;

	const mountBanner = createBannerMounter({
		mountComponent: (_component, options) => {
			const instance = { id: mountedInstances.length + 1, props: options.props };
			mountedInstances.push(instance);
			return instance;
		},
		unmountComponent: (instance) => {
			unmountedInstances.push(instance);
		},
		component: function FakeBanner() {},
		windowRef: null,
		documentRef: null,
	});

	const container = { replaceChildren: () => { replaceChildrenCalls++; } };
	const first = mountBanner(container, () => {});
	const second = mountBanner(container, () => {});

	expect(mountedInstances).toHaveLength(2);
	expect(unmountedInstances).toEqual([first.instance]);
	expect(replaceChildrenCalls).toBe(2);

	second.destroy();
	expect(unmountedInstances).toEqual([first.instance, second.instance]);
});
