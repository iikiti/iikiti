<script lang="ts">
	import { onMount } from 'svelte';
	import { notifications } from '../../../js/iikiti/notifications.js';
	import FormField from '$components/FormField.svelte';
	import SelectInput from '$components/SelectInput.svelte';
	import ToggleInput from '$components/ToggleInput.svelte';
	import TextInput from '$components/TextInput.svelte';
	import TextareaInput from '$components/TextareaInput.svelte';

	interface Field {
		key: string;
		label: string;
		type: string;
		required: boolean;
		options?: Array<{ value: string; label: string }>;
	}

	export interface Props {
		flow: string;
		action: string;
		csrf: string;
	}

	let { flow, action, csrf }: Props = $props();

	let fields = $state<Field[]>([]);
	let loading = $state(true);
	let error = $state<string | null>(null);
	let values = $state<Record<string, unknown>>({});

	onMount(async () => {
		try {
			const res = await fetch(`/flow/${encodeURIComponent(flow)}/step`, { credentials: 'same-origin' });
			if (!res.ok) throw new Error(`HTTP ${res.status}`);
			const data = (await res.json()) as { fields?: Field[]; complete?: boolean };
			if (data.complete) {
				// Workflow finished server-side: reveal the live page.
				window.location.assign(window.location.pathname);
				return;
			}
			fields = data.fields ?? [];
		} catch (e: any) {
			error = e?.message ?? 'Failed to load step';
			notifications.notify({ message: error, type: 'error' });
		} finally {
			loading = false;
		}
	});

	function onChange(key: string, value: unknown) {
		values = { ...values, [key]: value };
	}

	function serialize(): Record<string, unknown> {
		const out: Record<string, unknown> = {};
		for (const f of fields) {
			out[f.key] = values[f.key];
		}

		return out;
	}
</script>

{#if loading}
	<p class="iikiti-flow__loading">Loading…</p>
{:else if error}
	<p class="iikiti-flow__error">{error}</p>
{:else}
	<form method="post" action={action} class="iikiti-flow">
		<input type="hidden" name="_token" value={csrf} />
		{#each fields as field (field.key)}
			<FormField label={field.label}>
				{#if field.type === 'select'}
					<SelectInput
						value={String(values[field.key] ?? '')}
						options={field.options ?? []}
						onChange={(v) => onChange(field.key, v)}
					/>
				{:else if field.type === 'toggle'}
					<ToggleInput
						checked={Boolean(values[field.key])}
						onChange={(v) => onChange(field.key, v)}
					/>
				{:else if field.type === 'textarea'}
					<TextareaInput value={String(values[field.key] ?? '')} onInput={(v) => onChange(field.key, v)} />
				{:else}
					<TextInput value={String(values[field.key] ?? '')} onInput={(v) => onChange(field.key, v)} />
				{/if}
			</FormField>
		{/each}
		<button type="submit" class="iikiti-btn iikiti-btn--primary">Continue</button>
	</form>
{/if}

<style>
	:global(.js .native-form) { display: none; }
	.iikiti-flow__loading, .iikiti-flow__error { font-size: 0.9rem; opacity: 0.8; }
	.iikiti-flow { display: flex; flex-direction: column; gap: 10px; }
</style>
