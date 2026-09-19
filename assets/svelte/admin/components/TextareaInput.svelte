<script lang="ts">
	interface Props {
		value?: string | null;
		placeholder?: string;
		rows?: number;
		disabled?: boolean;
		required?: boolean;
		oninput?: (value: string) => void;
	}

	let {
		value,
		placeholder,
		rows = 4,
		disabled = false,
		required = false,
		oninput,
	}: Props = $props();

	let inputValue = $state(value ?? '');

	function handleInput(e: Event) {
		const target = e.target as HTMLTextAreaElement;
		inputValue = target.value;
		oninput?.(target.value);
	}
</script>

<textarea
	class="admin-input w-full resize-y"
	{placeholder}
	rows={rows}
	bind:value={inputValue}
	{disabled}
	{required}
	oninput={handleInput}
></textarea>
