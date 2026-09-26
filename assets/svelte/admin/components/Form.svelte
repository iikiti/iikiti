<script lang="ts">
	import FormField from './FormField.svelte';

	interface FieldConfig {
		key: string;
		label: string;
		type: 'text' | 'textarea' | 'json' | 'select' | 'checkbox' | 'toggle' | 'number';
		required?: boolean;
		placeholder?: string;
		options?: Array<{ value: string; label: string }>;
	}

	interface Props {
		fields: FieldConfig[];
		values?: Record<string, any>;
		errors?: Record<string, string>;
		onchange?: (values: Record<string, any>) => void;
		onsubmit?: (data: Record<string, any>) => void | boolean;
		children?: any;
	}

	let { fields, values = {}, errors = {}, onchange, onsubmit, children }: Props = $props();

	let formData = $state<Record<string, any>>({});

	$effect(() => {
		formData = { ...values };
	});

	$effect(() => {
		onchange?.(formData);
	});
</script>

<form onsubmit={(e) => { e.preventDefault(); onsubmit?.(formData); }}>
	<div class="space-y-4">
		{#each fields as field (field.key)}
			<FormField {field} error={errors[field.key]}>
				{#if field.type === 'textarea'}
					<textarea
						id={field.key}
						class="admin-input w-full resize-y"
						placeholder={field.placeholder}
						bind:value={formData[field.key]}
					></textarea>
				{:else if field.type === 'json'}
					<textarea
						id={field.key}
						class="admin-input w-full font-mono text-sm resize-y"
						placeholder={field.placeholder ?? '[]'}
						bind:value={formData[field.key]}
					></textarea>
					<p class="mt-1 text-xs text-text-muted">Enter valid JSON.</p>
				{:else if field.type === 'select'}
					<select
						id={field.key}
						class="admin-input w-full"
						bind:value={formData[field.key]}
					>
						{#each field.options ?? [] as option}
							<option value={option.value}>{option.label}</option>
						{/each}
					</select>
				{:else if field.type === 'checkbox'}
						<input
							type="checkbox"
							class="h-4 w-4 text-accent rounded"
							checked={formData[field.key]}
							onchange={(e) => (formData[field.key] = e.currentTarget.checked)}
						/>
				{:else if field.type === 'toggle'}
					<button
						type="button"
						class="admin-btn"
						class:admin-btn-primary={formData[field.key]}
						class:admin-btn-secondary={!formData[field.key]}
						onclick={() => (formData[field.key] = !formData[field.key])}
					>
						{formData[field.key] ? 'Yes' : 'No'}
					</button>
				{:else}
					<input
						id={field.key}
						type={field.type}
						class="admin-input w-full"
						placeholder={field.placeholder}
						bind:value={formData[field.key]}
					/>
				{/if}
			</FormField>
		{/each}
	</div>
	<div class="mt-4 flex justify-end gap-2">
		{@render children?.()}
	</div>
</form>
