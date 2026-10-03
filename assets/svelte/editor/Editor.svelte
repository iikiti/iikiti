<script lang="ts">
	import { onMount, onDestroy } from 'svelte';
	import { mount, unmount } from 'svelte';
	import { regions, init, layersOpen, activeRegion, select } from './state';
	import Region from './Region.svelte';
	import LayerMenu from './LayerMenu.svelte';
	import Toolbar from './Toolbar.svelte';
	import SettingsSidebar from './SettingsSidebar.svelte';
	import TourPopover from '$components/TourPopover.svelte';
	import NotificationCenter from '$components/NotificationCenter.svelte';
	import { bars } from '$framework/chrome/bars.js';
	import { loader } from '$framework/loader.js';
	import { registerAction } from '$framework/tour.js';
	import { registerCoreSidebar } from './coreSidebar.js';
	import { setActiveSection } from './extensions.js';

	export interface Props {
		config: Record<string, unknown>;
	}

	let { config }: Props = $props();
	let blockTypesList: Array<Record<string, unknown>> = [];
	let toolbarApp: ReturnType<typeof mount> | null = null;
	let toolbarBar: { destroy(): void } | null = null;
	let sidebarApp: ReturnType<typeof mount> | null = null;
	let sidebarBar: { destroy(): void } | null = null;
	let sidebarSide = $state(initialSide());

	const SIDES = ['left', 'right', 'top', 'bottom'];

	const mainRegionId = $derived($regions.find((r) => r.role === 'main')?.id ?? null);

	function initialSide(): string {
		try {
			const s = localStorage.getItem('iikiti.editor.sidebar.side');
			if (s && SIDES.includes(s)) return s;
		} catch {
			/* storage unavailable */
		}
		return 'left';
	}

	function mountSidebar(side: string) {
		if (sidebarApp) unmount(sidebarApp);
		sidebarApp = null;
		sidebarBar?.destroy();
		sidebarBar = null;

		const bar = bars.register(null, {
			id: 'iikiti-editor-settings',
			side: side as 'left' | 'right' | 'top' | 'bottom',
			order: 0,
			sticky: true,
			resizable: true,
			size: 300,
			minSize: 240,
			maxSize: 480,
		});
		if (bar) {
			sidebarBar = bar;
			sidebarApp = mount(SettingsSidebar, { target: bar.el, props: { side, onFlip: flipSide } });
		}
	}

	function setSide(side: string) {
		sidebarSide = side;
		try {
			localStorage.setItem('iikiti.editor.sidebar.side', side);
		} catch {
			/* storage unavailable */
		}
		mountSidebar(side);
	}

	function flipSide() {
		const next = SIDES[(SIDES.indexOf(sidebarSide) + 1) % SIDES.length];
		setSide(next);
	}

	function activateRegion(id: string) {
		activeRegion.set(id);
		select(null);
	}

	async function loadPluginEditorUis() {
		const plugins = (config['plugins'] as Array<Record<string, unknown>> | undefined) ?? [];
		for (const plugin of plugins) {
			const ui = plugin['editor_ui'] as Record<string, unknown> | undefined;
			const entry = ui?.['entry'];
			if (typeof entry !== 'string' || '' === entry) continue;
			const slug = String(plugin['slug'] ?? '');
			const url = /^(https?:)?\/\//.test(entry) || entry.startsWith('/')
				? entry
				: `/admin-plugins/${encodeURIComponent(slug)}/${entry.replace(/^\.?\//, '')}`;
			try {
				if (typeof ui?.['css'] === 'string' && ui['css']) {
					await loader.loadStyle(
						ui['css'].startsWith('/') ? ui['css'] : `/admin-plugins/${encodeURIComponent(slug)}/${ui['css']}`,
					);
				}
				await import(/* webpackIgnore: true */ /* @vite-ignore */ url);
			} catch {
				/* a plugin editor UI bundle failing to load must not break the editor */
			}
		}
	}

	onMount(async () => {
		// Register the editor top bar with the viewport-bars standard first so the
		// (sticky) slot pushes the page content down from the earliest possible
		// frame. Order -100 keeps the toolbar closest to the viewport edge.
		const bar = bars.register(null, {
			id: 'iikiti-editor-toolbar',
			side: 'top',
			order: -100,
			sticky: true,
			resizable: false,
		});
		if (bar) {
			toolbarBar = bar;
			toolbarApp = mount(Toolbar, { target: bar.el });
		}

		// Core sidebar (tabs + field controls) registers through the same API
		// plugins use, then plugin editor UI bundles may extend it.
		registerCoreSidebar();
		mountSidebar(sidebarSide);

		// Tour actions the editor contributes (the framework provides the rest).
		registerAction('sidebar.tab', (action) => setActiveSection(String(action?.value ?? 'content')));
		registerAction('sidebar.side', (action) => {
			const value = String(action?.value ?? '');
			if (SIDES.includes(value)) setSide(value);
		});
		registerAction('open', (action) => {
			if (action?.target === 'layers') layersOpen.set(true);
		});

		const token = config['apiToken'] as string | undefined;
		const res = await fetch(`${config['apiBase'] ?? '/api'}/editor/context`, {
			credentials: 'same-origin',
			headers: token ? { 'X-AUTH-TOKEN': token } : {},
		});
		const data = (await res.json().catch(() => ({ blockTypes: [] }))) as {
			blockTypes?: Array<Record<string, unknown>>;
		};
		blockTypesList = data.blockTypes ?? [];
		init(config, blockTypesList);

		await loadPluginEditorUis();

		// Best-effort presence probe for the realtime room (Yjs relay is a Node sidecar).
		const contextType = config['contextType'] as string | undefined;
		const contextId = config['contextId'] as string | number | undefined;
		if (contextType && contextId !== undefined && contextId !== null) {
			const url = `${config['apiBase'] ?? '/api'}/editor/room/${encodeURIComponent(contextType)}/${encodeURIComponent(String(contextId))}?token=${encodeURIComponent(token ?? '')}`;
			fetch(url, { credentials: 'same-origin' }).catch(() => undefined);
		}
	});

	onDestroy(() => {
		if (toolbarApp) unmount(toolbarApp);
		toolbarApp = null;
		toolbarBar?.destroy();
		toolbarBar = null;
		if (sidebarApp) unmount(sidebarApp);
		sidebarApp = null;
		sidebarBar?.destroy();
		sidebarBar = null;
	});
</script>

<div class="iikiti-editor" data-iikiti-editor>
	<div class="iikiti-editor__canvas" data-tour="editor.canvas">
		{#each $regions as r (r.id)}
			<div class="iikiti-region-frame" class:locked={r.id !== $activeRegion} data-region={r.id}>
				<Region
					regionId={r.id}
					editable={r.id === $activeRegion}
					name={r.name || r.id}
					onActivate={activateRegion}
				/>
			</div>
		{/each}
	</div>

	{#if $activeRegion && mainRegionId && $activeRegion !== mainRegionId}
		<button type="button" class="iikiti-editor__back" onclick={() => activateRegion(mainRegionId)}>
			← Back to content
		</button>
	{/if}

	{#if $layersOpen}
		<LayerMenu />
	{/if}

	<TourPopover />
	<NotificationCenter />
</div>

<style>
	:global(body.iikiti-editor-body [data-component="BlockEditorComponent"]) { display: none; }
	:global(body) { margin: 0; }
	.iikiti-region-frame { margin: 0 auto 16px; max-width: 1280px; }
	.iikiti-region-frame.locked { opacity: 0.85; }
	.iikiti-editor__back {
		position: fixed;
		top: calc(1rem + var(--iikiti-bars-top, 0px));
		left: calc(1rem + var(--iikiti-bars-left, 0px));
		z-index: var(--iikiti-z-popover, 2000);
		border: 1px solid var(--ik-panel-border, #ddd6cb);
		background: var(--ik-panel-bg, #f9f7f2);
		color: var(--ik-panel-text, #3a3830);
		border-radius: 999px;
		padding: 6px 12px;
		font-size: 12.5px;
		font-weight: 600;
		cursor: pointer;
		box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
	}
	.iikiti-editor__back:hover {
		color: var(--ik-accent-hover, #945231);
	}
</style>
