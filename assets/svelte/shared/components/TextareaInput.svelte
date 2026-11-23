<script lang="ts">
	interface Props {
		id?: string;
		name?: string;
		ariaDescribedBy?: string;
		value?: string | null;
		placeholder?: string;
		rows?: number;
		disabled?: boolean;
		required?: boolean;
		oninput?: (value: string) => void;
	}

	let {
		id,
		name,
		ariaDescribedBy,
		value,
		placeholder,
		rows = 4,
		disabled = false,
		required = false,
		oninput,
	}: Props = $props();

	let inputValue = $state('');
	$effect(() => {
		inputValue = value ?? '';
	});

	function handleInput(e: Event) {
		const target = e.target as HTMLTextAreaElement;
		inputValue = target.value;
		oninput?.(target.value);
	}
</script>

<textarea
	class="admin-input w-full resize-y"
	{id}
	{name}
	aria-describedby={ariaDescribedBy}
	{placeholder}
	rows={rows}
	bind:value={inputValue}
	{disabled}
	{required}
	oninput={handleInput}
></textarea>
