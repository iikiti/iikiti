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

	$effect(() => {
		inspectorAnchor = $selected ? getBlockElement($selected) ?? null : null;
	});

	function onSave() {
		void saveDraft();
	}
</script>

<div class="iikti-editor" data-iikti-editor>
	<div class="iikti-editor__toolbar" role="toolbar" tabindex="-1" aria-label="Block editor">		<button class="iikti-btn" onclick={undo}>Undo</button>
		<button class="iikti-btn" onclick={redo}>Redo</button>
		<button class="iikti-btn iikti-btn--primary" onclick={onSave}>Save draft</button>
		{#if $canPublish}
			<button class="iikti-btn iikti-btn--success" onclick={() => publish()}>Publish</button>
		{/if}
		<span class="iikti-toolbar__spacer"></span>
		<a class="iikti-btn" href={window.location.pathname} target="_blank" rel="noopener noreferrer">View live</a>
	</div>

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
	.iikti-btn {
		padding: 5px 10px; border: 1px solid #d1d5db; border-radius: 4px;
		background: #f9fafb; color: #111827; cursor: pointer;
	}
	.iikti-btn--primary { background: #2563eb; color: #fff; }
	.iikti-btn--success { background: #16a34a; color: #fff; }
	.iikiti-region-frame { margin: 0 auto 16px; max-width: 1280px; }

	/* Dark theme: Tailwind `dark:` uses `prefers-color-scheme` here. */
	@media (prefers-color-scheme: dark) {
		:global(body.iikiti-editor-body) .iikti-editor__toolbar {
			background: rgba(17, 24, 39, 0.96);
			border-bottom-color: #374151;
		}
		:global(body.iikiti-editor-body) .iikti-btn {
			background: #1f2937;
			border-color: #374151;
			color: #f3f4f6;
		}
	}
	:global(.dark body.iikiti-editor-body) .iikti-editor__toolbar {
		background: rgba(17, 24, 39, 0.96);
		border-bottom-color: #374151;
	}
	:global(.dark body.iikiti-editor-body) .iikti-btn {
		background: #1f2937;
		border-color: #374151;
		color: #f3f4f6;
	}
</style>
