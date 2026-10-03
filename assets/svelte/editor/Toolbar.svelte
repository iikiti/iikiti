<script lang="ts">
	import { onMount } from 'svelte';
	import {
		undo,
		redo,
		saveDraft,
		publish,
		canPublish,
		canUndo,
		canRedo,
		dirty,
		layersOpen,
	} from './state';
	import Icon from '$components/Icon.svelte';
	import Tooltip from '$components/Tooltip.svelte';

	/**
	 * Editor top bar. Mounted by Editor.svelte into a bars-standard top slot
	 * (`iikiti.bars.register(null, { side: 'top', order: -100, sticky: true })`)
	 * so it is sticky — always pinned at the viewport edge — while pushing the
	 * page content down via document flow instead of covering it.
	 *
	 * All chrome colours flow from the shared `--ik-*` theme tokens, which
	 * flip with the `.dark` class applied by the layout's theme bootstrap —
	 * the sun/moon toggle below exposes the front-end theme API
	 * (`iikiti.theme.toggle()`, localStorage-persisted) directly on the bar.
	 */
	let dark = $state(false);

	onMount(() => {
		dark = document.documentElement.classList.contains('dark');
	});

	function toggleTheme() {
		const api = (window as unknown as { iikiti?: { theme?: { toggle?: () => void } } });
		if (api.iikiti?.theme?.toggle) {
			api.iikiti.theme.toggle();
		} else {
			// Fallback if the front-end theme helper is unavailable.
			document.documentElement.classList.toggle('dark');
			try {
				localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
			} catch {
				/* storage unavailable */
			}
		}
		dark = document.documentElement.classList.contains('dark');
	}

	function onSave() {
		void saveDraft();
	}

	function toggleLayers() {
		layersOpen.update((v) => !v);
	}
</script>

<div class="iikiti-editor-toolbar" role="toolbar" aria-label="Block editor actions">
	<Tooltip content="Undo">
		<button
			type="button"
			class="iikiti-editor-btn"
			disabled={!$canUndo}
			onclick={undo}
			aria-label="Undo"
		>
			<Icon name="undo-2" size={18} />
		</button>
	</Tooltip>

	<Tooltip content="Redo">
		<button
			type="button"
			class="iikiti-editor-btn"
			disabled={!$canRedo}
			onclick={redo}
			aria-label="Redo"
		>
			<Icon name="redo-2" size={18} />
		</button>
	</Tooltip>

	<span class="iikiti-editor-toolbar__divider" aria-hidden="true"></span>

	<Tooltip content={$dirty ? 'Save draft (unsaved changes)' : 'Save draft'}>
		<button
			type="button"
			class="iikiti-editor-btn"
			data-dirty={$dirty ? 'true' : undefined}
			onclick={onSave}
			aria-label="Save draft"
		>
			<Icon name="save" size={18} />
		</button>
	</Tooltip>

	{#if $canPublish}
		<Tooltip content="Publish">
			<button
				type="button"
				class="iikiti-editor-btn iikiti-editor-btn--primary"
				onclick={() => publish()}
				aria-label="Publish"
			>
				<Icon name="rocket" size={18} />
			</button>
		</Tooltip>
	{/if}

	<span class="iikiti-editor-toolbar__divider" aria-hidden="true"></span>

	<Tooltip content="Layers (page structure)">
		<button
			type="button"
			class="iikiti-editor-btn"
			class:active={$layersOpen}
			aria-pressed={$layersOpen}
			onclick={toggleLayers}
			aria-label="Toggle layers panel"
		>
			<Icon name="layers" size={18} />
		</button>
	</Tooltip>

	<Tooltip content={dark ? 'Switch to light theme' : 'Switch to dark theme'}>
		<button
			type="button"
			class="iikiti-editor-btn"
			onclick={toggleTheme}
			aria-label={dark ? 'Switch to light theme' : 'Switch to dark theme'}
		>
			<Icon name={dark ? 'moon' : 'sun'} size={18} />
		</button>
	</Tooltip>

	<span class="iikiti-editor-toolbar__spacer"></span>

	<a
		class="iikiti-editor-toolbar__link"
		href={window.location.pathname}
		target="_blank"
		rel="noopener noreferrer"
	>
		<Icon name="external-link" size={14} />
		<span>View live</span>
	</a>
</div>

<style>
	.iikiti-editor-toolbar {
		display: flex;
		align-items: center;
		gap: 2px;
		height: 44px;
		padding: 4px 10px;
		background: color-mix(in srgb, var(--ik-panel-bg, #ffffff) 94%, transparent);
		backdrop-filter: blur(6px);
		border-bottom: 1px solid var(--ik-panel-border, #e5e7eb);
		color: var(--ik-panel-text, #111827);
	}

	.iikiti-editor-btn {
		position: relative;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 34px;
		height: 32px;
		padding: 0;
		border: none;
		border-radius: 6px;
		background: transparent;
		color: inherit;
		cursor: pointer;
		transition:
			background-color 0.12s ease,
			color 0.12s ease,
			opacity 0.12s ease;
	}

	.iikiti-editor-btn:hover:not(:disabled),
	.iikiti-editor-btn.active,
	.iikiti-editor-btn:focus-visible {
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 10%, transparent);
	}

	.iikiti-editor-btn:active:not(:disabled) {
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 16%, transparent);
	}

	.iikiti-editor-btn:disabled {
		opacity: 0.35;
		cursor: default;
	}

	.iikiti-editor-btn--primary {
		color: var(--ik-accent, #a6613c);
	}

	.iikiti-editor-btn--primary:hover:not(:disabled),
	.iikiti-editor-btn--primary:focus-visible {
		background: color-mix(in srgb, var(--ik-accent, #a6613c) 14%, transparent);
		color: var(--ik-accent-hover, #945231);
	}

	/* Unsaved-changes indicator on the save-draft button. */
	.iikiti-editor-btn[data-dirty='true']::after {
		content: '';
		position: absolute;
		top: 5px;
		right: 5px;
		width: 6px;
		height: 6px;
		border-radius: 50%;
		background: var(--ik-accent, #a6613c);
	}

	.iikiti-editor-toolbar__divider {
		width: 1px;
		height: 20px;
		margin: 0 6px;
		background: var(--ik-panel-border, #e5e7eb);
	}

	.iikiti-editor-toolbar__spacer {
		flex: 1;
	}

	.iikiti-editor-toolbar__link {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		height: 32px;
		padding: 0 10px;
		border-radius: 6px;
		color: var(--ik-panel-text-muted, #6b7280);
		text-decoration: none;
		font-size: 12px;
		transition:
			background-color 0.12s ease,
			color 0.12s ease;
	}

	.iikiti-editor-toolbar__link:hover,
	.iikiti-editor-toolbar__link:focus-visible {
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 10%, transparent);
		color: var(--ik-panel-text, #111827);
	}
</style>
