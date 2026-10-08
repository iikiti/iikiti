<script lang="ts">
	import Icon from './Icon.svelte';

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
		info: 'info',
		success: 'check-circle',
		warning: 'alert-circle',
		error: 'alert-circle',
	};

	let timer: ReturnType<typeof setTimeout> | null = null;

	$effect(() => {
		if (duration != null && duration > 0) {
			timer = setTimeout(() => onDismiss(id), duration * 1000);
		}
		return () => {
			if (timer) clearTimeout(timer);
		};
	});
</script>

<div class={`iikiti-toast iikiti-toast--${type}`} role="status" aria-live={type === 'error' ? 'assertive' : 'polite'}>
	<span class="iikiti-toast__icon" aria-hidden="true"><Icon name={icons[type] ?? icons.info} size={16} /></span>
	<span class="iikiti-toast__message">{message}</span>
	{#if action}<button class="iikiti-toast__action" onclick={action.run}>{action.label}</button>{/if}
	<button class="iikiti-toast__dismiss" aria-label="Dismiss" onclick={() => onDismiss(id)}><Icon name="x" size={14} /></button>
</div>
