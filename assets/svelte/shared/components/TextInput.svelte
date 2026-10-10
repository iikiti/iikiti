<script lang="ts">
	interface Props {
		value?: string | number | null;
		placeholder?: string;
		type?: 'text' | 'email' | 'password' | 'number' | 'tel' | 'url';
		disabled?: boolean;
		required?: boolean;
		min?: number;
		max?: number;
		step?: number;
		oninput?: (value: string | number | null) => void;
	}

	let {
		value,
		placeholder,
		type = 'text',
		disabled = false,
		required = false,
		min,
		max,
		step,
		oninput,
	}: Props = $props();

	let inputValue = $state('');
	// Keep the visible value in sync if the parent updates the `value` prop.
	$effect(() => {
		inputValue = value ?? '';
	});

	function handleInput(e: Event) {
		const target = e.target as HTMLInputElement;
		inputValue = target.value;
		oninput?.(target.value);
	}
</script>

<input
	type={type}
	class="admin-input w-full"
	{placeholder}
	bind:value={inputValue}
	{disabled}
	{required}
	{min}
	{max}
	{step}
	oninput={handleInput}
/>
