<script lang="ts">
	interface Props {
		value?: string;
		placeholder?: string;
		onSearch?: (value: string) => void;
	}

	let { value = '', placeholder = 'Search…', onSearch }: Props = $props();

	let inputValue = $state('');
	let debounce: ReturnType<typeof setTimeout>;

	$effect(() => {
		inputValue = value;
	});

	$effect(() => {
		clearTimeout(debounce);
		debounce = setTimeout(() => {
			onSearch?.(inputValue);
		}, 300);
	});
</script>

<div class="relative">
	<input
		type="search"
		class="admin-input pl-10 pr-3 py-2 text-sm"
		placeholder={placeholder}
		bind:value={inputValue}
	/>
	<div class="absolute left-3 top-2.5 text-gray-400 dark:text-gray-500">🔍</div>
</div>
