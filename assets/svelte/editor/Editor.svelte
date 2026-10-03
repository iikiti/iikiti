<script lang="ts">
	import { onMount, onDestroy } from 'svelte';
	import { mount, unmount } from 'svelte';
	import { regions, selected, init, getBlockElement, layersOpen } from './state';
	import Region from './Region.svelte';
	import Inspector from './Inspector.svelte';
	import LayerMenu from './LayerMenu.svelte';
	import Toolbar from './Toolbar.svelte';
	import NotificationCenter from '$components/NotificationCenter.svelte';
	import { bars } from '$framework/chrome/bars.js';

	export interface Props {
		config: Record<string, unknown>;
	}

	let { config }: Props = $props();
	let blockTypesList: Array<Record<string, unknown>> = [];
	let inspectorAnchor: HTMLElement | null = $state(null);
	let toolbarApp: ReturnType<typeof mount> | null = null;
	let toolbarBar: { destroy(): void } | null = null;

	onMount(async () => {
		// Register the editor top bar with the viewport-bars standard first so
		// the (sticky) slot pushes the page content down from the earliest
		// possible frame. Order -100 keeps the toolbar closest to the viewport
		// edge; `sticky: true` pins it there while editing; never resizable.
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

		// Best-effort presence probe for the realtime room (Yjs relay is a Node sidecar).
		// The room endpoint is /api/editor/room/{contextType}/{contextId}; do NOT reuse
		// `config.room` (a WebSocket channel name like "room/template:26"), which would
		// produce a doubled `room/` segment and a 404.
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
	});

	$effect(() => {
		inspectorAnchor = $selected ? getBlockElement($selected) ?? null : null;
	});
</script>

<div class="iikiti-editor" data-iikti-editor>
	<div class="iikiti-editor__canvas">
		{#each $regions as r (r.id)}
			<div class="iikiti-region-frame" data-region={r.id}>
				<Region regionId={r.id} />
			</div>
		{/each}
	</div>

	{#if $layersOpen}
		<LayerMenu />
	{/if}

	{#if inspectorAnchor}
		<Inspector anchor={inspectorAnchor} />
	{/if}

	<NotificationCenter />
</div>

<style>
	:global(body.iikiti-editor-body [data-component="BlockEditorComponent"]) { display: none; }
	:global(body) { margin: 0; }
	.iikiti-region-frame { margin: 0 auto 16px; max-width: 1280px; }
</style>
