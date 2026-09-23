<script lang="ts">
	import { onMount, onDestroy } from 'svelte';
	import {
		regions,
		selected,
		init,
		undo,
		redo,
		saveDraft,
		publish,
		canPublish,
		getBlockElement,
	} from './state';
	import Region from './Region.svelte';
	import Inspector from './Inspector.svelte';
	import NotificationCenter from '$components/NotificationCenter.svelte';

	export interface Props {
		config: Record<string, unknown>;
	}

	let { config }: Props = $props();
	let blockTypesList: Array<Record<string, unknown>> = [];
	let inspectorAnchor: HTMLElement | null = $state(null);

	onMount(async () => {
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
		const room = config['room'] as string | undefined;
		if (room) {
			const url = `${config['apiBase'] ?? '/api'}/editor/room/${encodeURIComponent(room)}?token=${encodeURIComponent(token ?? '')}`;
			fetch(url, { credentials: 'same-origin' }).catch(() => undefined);
		}
	});

	$effect(() => {
		inspectorAnchor = $selected ? getBlockElement($selected) ?? null : null;
	});

	function onSave() {
		void saveDraft();
	}
</script>

<div class="iikti-editor" data-iikti-editor>
	<nav class="iikti-editor__toolbar" role="toolbar" aria-label="Block editor">
		<button class="iikti-btn" onclick={undo}>Undo</button>
		<button class="iikti-btn" onclick={redo}>Redo</button>
		<button class="iikti-btn iikti-btn--primary" onclick={onSave}>Save draft</button>
		{#if $canPublish}
			<button class="iikti-btn iikti-btn--success" onclick={() => publish()}>Publish</button>
		{/if}
		<span class="iikti-toolbar__spacer"></span>
		<a class="iikti-btn" href={window.location.pathname} target="_blank" rel="noopener noreferrer">View live</a>
	</nav>

	<div class="iikiti-editor__canvas">
		{#each $regions as r (r.id)}
			<div class="iikiti-region-frame" data-region={r.id}>
				<Region regionId={r.id} />
			</div>
		{/each}
	</div>

	{#if inspectorAnchor}
		<Inspector anchor={inspectorAnchor} />
	{/if}

	<NotificationCenter />
</div>

<style>
	:global(body.iikiti-editor-body [data-component="BlockEditorComponent"]) { display: none; }
	:global(body) { margin: 0; }
	.iikti-editor__toolbar {
		position: fixed; top: 0; left: 0; right: 0; height: 44px;
		display: flex; align-items: center; gap: 6px; padding: 6px 10px;
		background: rgba(255, 255, 255, 0.96); backdrop-filter: blur(4px);
		border-bottom: 1px solid #e5e7eb; z-index: 10000;
	}
	.iikti-toolbar__spacer { flex: 1; }
	.iikti-btn { padding: 5px 10px; border: 1px solid #d1d5db; border-radius: 4px; background: #f9fafb; cursor: pointer; }
	.iikti-btn--primary { background: #2563eb; color: #fff; }
	.iikti-btn--success { background: #16a34a; color: #fff; }
	.iikti-editor__canvas { margin-top: 52px; padding: 16px; }
	.iikiti-region-frame { margin: 0 auto 16px; max-width: 1280px; }
</style>
