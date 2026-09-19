<script lang="ts">
	import type { ComponentStatus } from '$types';

	interface Props {
		status?: ComponentStatus;
		outline?: boolean;
	}

	let { status = 'primary', outline = false, children }: Props = $props();

	const variantClasses: Record<string, string> = {
		success: 'bg-green-100 dark:bg-green-900/20 text-green-800 dark:text-green-200',
		warning: 'bg-yellow-100 dark:bg-yellow-900/20 text-yellow-800 dark:text-yellow-200',
		danger: 'bg-red-100 dark:bg-red-900/20 text-red-800 dark:text-red-200',
		info: 'bg-blue-100 dark:bg-blue-900/20 text-blue-800 dark:text-blue-200',
		primary: 'bg-blue-100 dark:bg-blue-900/20 text-blue-800 dark:text-blue-200',
	};

	const outlineClasses: Record<string, string> = {
		success: 'bg-transparent border border-green-600 text-green-600',
		warning: 'bg-transparent border border-yellow-600 text-yellow-600',
		danger: 'bg-transparent border border-red-600 text-red-600',
		info: 'bg-transparent border border-blue-600 text-blue-600',
		primary: 'bg-transparent border border-blue-600 text-blue-600',
	};
</script>

<span
	class="admin-badge {outline ? outlineClasses[status] : variantClasses[status]}"
	class:text-xs={outline}
>
	{@render children?.()}
</span>
