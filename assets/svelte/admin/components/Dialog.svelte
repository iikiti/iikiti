<script lang="ts">
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
	<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
		<div
			class="bg-white dark:bg-gray-800 rounded-lg shadow-xl {sizeClasses[size]} w-full mx-4"
		>
			{#if title || showClose}
				<div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
					{#if title}<h3 class="font-medium text-gray-900 dark:text-white">{title}</h3>{/if}
					{#if showClose}
						<button
							class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
							onclick={() => onClose?.()}
						>
							✕
						</button>
					{/if}
				</div>
			{/if}
			<div class="p-4 overflow-y-auto max-h-[70vh]">
				{@render children?.()}
			</div>
			{#if footer}
				<div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
					{@render footer?.()}
				</div>
			{/if}
		</div>
	</div>
{/if}
