<script lang="ts">
	import type { ComponentVariant, ComponentSize } from '$types';

	interface Props {
		variant?: ComponentVariant;
		size?: ComponentSize;
		fullWidth?: boolean;
		disabled?: boolean;
		type?: 'button' | 'submit' | 'reset';
		icon?: string;
		iconPosition?: 'left' | 'right';
		onclick?: () => void;
	}

	let {
		variant = 'primary',
		size = 'md',
		fullWidth = false,
		disabled = false,
		type = 'button',
		icon,
		iconPosition = 'left',
		onclick,
		children,
	}: Props = $props();

	const baseClasses = 'admin-btn inline-inline-flex items-center justify-center rounded-lg font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed';

	const variantClasses: Record<string, string> = {
		primary: 'bg-blue-600 hover:bg-blue-700 text-white focus:ring-blue-500',
		secondary: 'bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-white focus:ring-gray-500',
		danger: 'bg-red-600 hover:bg-red-700 text-white focus:ring-red-500',
		ghost: 'hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-400 focus:ring-gray-500',
		icon: 'bg-transparent hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-400 focus:ring-gray-500 p-2',
	};

	const sizeClasses: Record<string, string> = {
		sm: 'px-2.5 py-1.5 text-xs',
		md: 'px-4 py-2 text-sm',
		lg: 'px-6 py-3 text-base',
	};

	const widthClass = $derived(fullWidth ? 'w-full' : '');
	const iconClass = $derived(icon ? (iconPosition === 'left' ? 'mr-2' : 'ml-2') : '');
</script>

<button
	{type}
	class="{baseClasses} {variantClasses[variant]} {sizeClasses[size]} {widthClass} {iconClass}"
	{disabled}
	onclick={onclick}
>
	{#if icon && iconPosition === 'left'}<span class="icon">{icon}</span>{/if}
	{@render children?.()}
	{#if icon && iconPosition === 'right'}<span class="icon">{icon}</span>{/if}
</button>
