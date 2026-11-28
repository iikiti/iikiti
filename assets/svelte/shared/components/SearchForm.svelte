<script lang="ts">
	import Icon from './Icon.svelte';

	interface Props {
		value?: string;
		placeholder?: string;
		onSearch?: (value: string) => void;
	}

	let { value = '', placeholder = 'Search…', onSearch }: Props = $props();

	let inputValue = $state('');
	let debounce: ReturnType<typeof setTimeout> | undefined;

	$effect(() => {
		inputValue = value ?? '';
	});

	$effect(() => {
		const current = inputValue;
		clearTimeout(debounce);
		if (current === (value ?? '')) {
			return () => clearTimeout(debounce);
		}
		debounce = setTimeout(() => {
			onSearch?.(current);
		}, 300);
		return () => clearTimeout(debounce);
	});
</script>

<div class="relative">
	<input
		type="search"
		class="admin-input pl-10 pr-3 py-2 text-sm"
		placeholder={placeholder}
		bind:value={inputValue}
	/>
	<div class="absolute left-3 top-2.5 text-text-muted">
		<Icon name="search" size={16} />
	</div>
</div>
