<script lang="ts">
	import { onMount } from 'svelte';
	import Popover from './Popover.svelte';
	import { on as onTour, resolveTarget, next, prev, end } from '$framework/tour.js';

	/**
	 * Renders the active tour step as a popover above/beside its target.
	 * Mounted once per UI (editor + admin); the tour state lives in
	 * `assets/js/iikiti/tour.js`.
	 */
	let state = $state<{ tourId: string; stepIndex: number; total: number; step: Record<string, unknown> } | null>(null);
	let anchorEl = $state<HTMLElement | null>(null);

	onMount(() => onTour((s) => (state = s)));

	$effect(() => {
		const step = state?.step;
		if (!step) {
			anchorEl = null;
			return;
		}
		const el = resolveTarget(step['target'] as string | null);
		anchorEl = el;
		if (!el && step['target']) {
			const timer = setTimeout(() => {
				anchorEl = resolveTarget(step['target'] as string | null);
			}, 120);
			return () => clearTimeout(timer);
		}
	});
</script>

{#if state?.step}
	{#if anchorEl}
		<Popover
			anchor={anchorEl}
			placement={(state.step['placement'] as 'top' | 'bottom' | 'left' | 'right') ?? 'bottom'}
			flip
			closeOnOutside={false}
			closeOnEsc={false}
		>
			{@render content()}
		</Popover>
	{:else}
		<div class="iikiti-tour iikiti-tour--floating" role="dialog" aria-label="Tour step">
			{@render content()}
		</div>
	{/if}
{/if}

{#snippet content()}
	<div class="iikiti-tour" data-tour-step={state?.stepIndex ?? 0}>
		{#if state?.step['title']}
			<h4 class="iikiti-tour__title">{state.step['title']}</h4>
		{/if}
		<div class="iikiti-tour__body">
			{#if state?.step['html']}
				{@html String(state.step['content'] ?? '')}
			{:else}
				{String(state?.step['content'] ?? '')}
			{/if}
		</div>
		<div class="iikiti-tour__footer">
			<span class="iikiti-tour__count">{(state?.stepIndex ?? 0) + 1} / {state?.total ?? 1}</span>
			<div class="iikiti-tour__buttons">
				<button type="button" disabled={(state?.stepIndex ?? 0) === 0} onclick={() => prev()}>Back</button>
				<button type="button" class="primary" onclick={() => next()}>
					{(state?.stepIndex ?? 0) >= (state?.total ?? 1) - 1 ? 'Done' : 'Next'}
				</button>
				<button type="button" class="close" title="End tour" aria-label="End tour" onclick={() => end()}>×</button>
			</div>
		</div>
	</div>
{/snippet}

<style>
	.iikiti-tour {
		display: flex;
		flex-direction: column;
		gap: 8px;
		min-width: 220px;
		max-width: 320px;
		padding: 10px 12px;
		color: var(--ik-panel-text, #3a3830);
	}
	.iikiti-tour--floating {
		position: fixed;
		z-index: var(--iikiti-z-tooltip);
		left: 50%;
		bottom: 24px;
		transform: translateX(-50%);
		border: 1px solid var(--ik-panel-border, #ddd6cb);
		border-radius: var(--ik-radius, 0.75rem);
		background: var(--ik-panel-bg, #f9f7f2);
		box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
	}
	.iikiti-tour__title {
		margin: 0;
		font-size: 13px;
		font-weight: 600;
	}
	.iikiti-tour__body {
		font-size: 12.5px;
		line-height: 1.45;
		color: var(--ik-panel-text-muted, #5b574d);
	}
	.iikiti-tour__footer {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
	}
	.iikiti-tour__count {
		font-size: 11px;
		color: var(--ik-panel-text-muted, #7a7669);
	}
	.iikiti-tour__buttons {
		display: flex;
		gap: 4px;
	}
	.iikiti-tour__buttons button {
		border: 1px solid var(--ik-panel-border, #ddd6cb);
		border-radius: 6px;
		background: transparent;
		color: var(--ik-panel-text, #3a3830);
		font-size: 12px;
		padding: 3px 8px;
		cursor: pointer;
	}
	.iikiti-tour__buttons button.primary {
		background: var(--ik-accent, #a6613c);
		border-color: var(--ik-accent, #a6613c);
		color: #fff;
	}
	.iikiti-tour__buttons button:disabled {
		opacity: 0.45;
		cursor: default;
	}
	.iikiti-tour__buttons button.close {
		padding: 3px 7px;
		font-size: 14px;
		line-height: 1;
	}
</style>
