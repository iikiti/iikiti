<script lang="ts">
	import Icon from './Icon.svelte';
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

	const widthClass = $derived(fullWidth ? 'w-full' : '');
	const iconMargin = $derived(icon ? (iconPosition === 'left' ? 'mr-2' : 'ml-2') : '');
</script>

<button
	{type}
	class={`admin-btn admin-btn-${size} admin-btn-${variant} ${widthClass} ${iconMargin}`}
	{disabled}
	onclick={onclick}
>
	{#if icon && iconPosition === 'left'}<Icon name={icon} size={16} />{/if}
	{@render children?.()}
	{#if icon && iconPosition === 'right'}<Icon name={icon} size={16} />{/if}
</button>
