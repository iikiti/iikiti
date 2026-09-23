<script lang="ts">
	import { onDestroy } from 'svelte';

	interface Props {
		id: string;
		message: string;
		type?: 'info' | 'success' | 'warning' | 'error';
		duration?: number | null;
		action?: { label: string; run: () => void };
		onDismiss: (id: string) => void;
	}

	let { id, message, type = 'info', duration = 10, action, onDismiss }: Props = $props();

	const icons: Record<string, string> = {
		info: 'ℹ',
		success: '✓',
		warning: '⚠',
		error: '✕',
	};

	let timer: ReturnType<typeof setTimeout> | null = null;
	if (duration != null && duration > 0) {
		timer = setTimeout(() => onDismiss(id), duration * 1000);
	}

	onDestroy(() => {
		if (timer) clearTimeout(timer);
	});
</script>

<div class={`iikiti-toast iikiti-toast--${type}`} role="status" aria-live={type === 'error' ? 'assertive' : 'polite'}>
	<span class="iikiti-toast__icon" aria-hidden="true">{icons[type] ?? icons.info}</span>
	<span class="iikiti-toast__message">{message}</span>
	{#if action}<button class="iikiti-toast__action" onclick={action.run}>{action.label}</button>{/if}
	<button class="iikiti-toast__dismiss" aria-label="Dismiss" onclick={() => onDismiss(id)}>✕</button>
</div>
