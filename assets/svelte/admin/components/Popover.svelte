<script lang="ts">
	import { type Snippet, tick } from 'svelte';
	import { onMount } from 'svelte';

	let {
		anchor,
		open = false,
		placement = 'bottom',
		flip = true,
		closeOnOutside = true,
		closeOnEsc = true,
		onclose,
		children,
	}: {
		anchor: HTMLElement | null;
		open?: boolean;
		placement?: 'top' | 'bottom' | 'left' | 'right';
		flip?: boolean;
		closeOnOutside?: boolean;
		closeOnEsc?: boolean;
		onclose?: () => void;
		children: Snippet;
	} = $props();

	let self: HTMLDivElement;
	let active = $state(open);
	let style = $state<Record<string, string>>({});

	function position() {
		tick().then(() => {
			if (!anchor || !active) {
				style = {};
				return;
			}
			const rect = anchor.getBoundingClientRect();
			const box = self.getBoundingClientRect();
			const offset = 8;
			let top = 0;
			let left = 0;

			switch (placement) {
				case 'top':
					top = rect.top - box.height - offset;
					left = rect.left + (rect.width - box.width) / 2;
					break;
				case 'left':
					top = rect.top + (rect.height - box.height) / 2;
					left = rect.left - box.width - offset;
					break;
				case 'right':
					top = rect.top + (rect.height - box.height) / 2;
					left = rect.right + offset;
					break;
				default:
					top = rect.bottom + offset;
					left = rect.left + (rect.width - box.width) / 2;
			}

			if (flip) {
				const vh = window.innerHeight;
				const vw = window.innerWidth;
				if (top < 20 && placement === 'top') {
					top = rect.bottom + offset;
				}
				if (top + box.height > vh && placement === 'bottom') {
					top = rect.top - box.height - offset;
				}
				if (left < 10) left = 10;
				if (left + box.width > vw) left = vw - box.width - 10;
			}

			style = { top: `${Math.round(top)}px`, left: `${Math.round(left)}px` };
		});
	}

	$effect(() => {
		if (active) {
			position();
		}
	});

	function outside(ev: MouseEvent) {
		if (!closeOnOutside || !self) return;
		const path = ev.composedPath?.() ?? [];
		if (!path.includes(self) && (!anchor || !path.includes(anchor))) {
			close();
		}
	}

	function close() {
		active = false;
		onclose?.();
	}

	onMount(() => {
		const cleanups: Array<() => void> = [];
		if (closeOnOutside) {
			window.addEventListener('click', outside, true);
			cleanups.push(() => window.removeEventListener('click', outside, true));
		}
		if (closeOnEsc) {
			const key = (e: KeyboardEvent) => {
				if (e.key === 'Escape') close();
			};
			window.addEventListener('keydown', key);
			cleanups.push(() => window.removeEventListener('keydown', key));
		}
		return () => cleanups.forEach((fn) => fn());
	});
</script>

{#if active}
<div bind:this={self} class="iikiti-popover" style={style}>
	{@render children()}
</div>
{/if}
