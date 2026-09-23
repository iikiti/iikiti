<script lang="ts">
	import { tick } from 'svelte';
	import { notifications, settings } from '$framework/notifications';
	import type { Notification } from '$framework/types';
	import Toast from './Toast.svelte';

	type Item = Notification & { dismissed: boolean };
	let items = $state<Item[]>([]);
	let unsubscribe: (() => void) | null = null;

	const position = $derived(settings.position);

	$effect(() => {
		unsubscribe?.();
		unsubscribe = notifications.on((ev) => {
			if (ev.kind === 'notify') {
				items = [{ ...ev.notification, dismissed: false }, ...items];
			} else {
				items = items.map((i) => (i.id === ev.id ? { ...i, dismissed: true } : i)).filter((i) => !i.dismissed);
			}
			tick();
		});
	});
</script>

<div class="iikiti-notification-center" data-position={position as string}>
	{#each items as item (item.id)}
		<Toast
			id={item.id}
			message={item.message}
			type={item.type}
			duration={item.duration ?? settings.durationSeconds}
			action={item.action}
			onDismiss={(id) => notifications.dismiss(id)}
		/>
	{/each}
	{#if items.length > 3}
		<div class="iikiti-notification-center__scroll" aria-label="More notifications"></div>
	{/if}
</div>
