<script lang="ts">
	import Icon from './Icon.svelte';

	interface Props {
		open?: boolean;
		title?: string;
		size?: 'sm' | 'md' | 'lg';
		showClose?: boolean;
		onClose?: () => void;
	}

	let { open = false, title, size = 'md', showClose = true, onClose, children, footer }: Props = $props();

	const sizeClasses: Record<string, string> = {
		sm: 'max-w-sm',
		md: 'max-w-md',
		lg: 'max-w-2xl',
	};
</script>

{#if open}
	<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" onmousedown={(e) => e.target === e.currentTarget && onClose?.()}>
		<div
			class="rounded-lg border bg-surface text-text shadow-xl {sizeClasses[size]} w-full mx-4"
		>
			{#if title || showClose}
				<div class="admin-card-header">
					{#if title}<h3 class="font-medium text-text">{title}</h3>{/if}
					{#if showClose}
						<button
							class="text-text-subtle hover:text-text"
							aria-label="Close"
							onclick={() => onClose?.()}
						>
							<Icon name="x" size={18} />
						</button>
					{/if}
				</div>
			{/if}
			<div class="p-4 overflow-y-auto max-h-[70vh]">
				{@render children?.()}
			</div>
			{#if footer}
				<div class="admin-card-footer">
					{@render footer?.()}
				</div>
			{/if}
		</div>
	</div>
{/if}
