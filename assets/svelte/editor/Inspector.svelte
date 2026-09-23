<script lang="ts">
	import { get } from 'svelte/store';
	import { selected, blockTypes, updateNode } from './state';
	import FormField from '$components/FormField.svelte';
	import SelectInput from '$components/SelectInput.svelte';
	import ToggleInput from '$components/ToggleInput.svelte';
	import TextInput from '$components/TextInput.svelte';
	import TextareaInput from '$components/TextareaInput.svelte';

	let { anchor }: { anchor: HTMLElement | null } = $props();

	let activeTab = $state('content');

	const node = $derived(getSelected());
	const schema = $derived(node ? get(blockTypes)[node.type] : null);

	function getSelected(): BlockNode | null {
		const id = get(selected);
		if (!id) return null;
		const fn = (window as unknown as { __iikitiSearch?: (id: string) => BlockNode | null }).__iikitiSearch;
		return fn ? fn(id) : null;
	}

	function setField(field: { key: string }, value: unknown) {
		if (!node || !schema) return;
		const contentFields = (schema.contentFields ?? []) as Array<Record<string, unknown>>;
		const isContent = contentFields.some((f) => f.key === field.key);
		const content = { ...(node.content ?? {}) };
		const base = { ...((node.style ?? {}).base ?? {}) };

		if (isContent) {
			content[field.key] = value;
			updateNode(node.id, { content });
		} else {
			base[field.key] = value;
			updateNode(node.id, { style: { ...node.style, base } });
		}
	}

	function fieldValue(field: { type?: unknown; key: string }) {
		if (!node) return undefined;
		const base = (node.style ?? {}).base ?? {};
		const contentFields = ((schema?.contentFields) ?? []) as Array<Record<string, unknown>>;
		const isContent = contentFields.some((f) => f.key === field.key);
		return isContent ? node.content?.[field.key] : base[field.key];
	}
</script>

{#if node && schema}
	<Popover {anchor} placement="right" closeOnOutside onclose={() => { }}>
		<div class="iikiti-inspector">
			<h3 class="iikiti-inspector__title">{schema.label ?? node.type} — edit</h3>
			<div class="iikiti-inspector__tabs">
				<button class:selected={activeTab === 'content'} onclick={() => (activeTab = 'content')}>Content</button>
				<button class:selected={activeTab === 'style'} onclick={() => (activeTab = 'style')}>Style</button>
			</div>
			{#if activeTab === 'content'}
				{#each schema.contentFields as field (field.key)}
					<FormField label={String(field.label ?? field.key)}>
						{#if field.type === 'select'}
							<SelectInput
								value={String(fieldValue(field) ?? '')}
								options={(field.options ?? []) as any}
								onChange={(v: string) => setField(field, v)}
							/>
						{:else if field.type === 'toggle'}
							<ToggleInput
								checked={Boolean(fieldValue(field))}
								onChange={(v: boolean) => setField(field, v)}
							/>
						{:else if field.type === 'textarea'}
							<TextareaInput
								value={String(fieldValue(field) ?? '')}
								onInput={(v: string) => setField(field, v)}
							/>
						{:else}
							<TextInput
								value={String(fieldValue(field) ?? '')}
								placeholder={field.type === 'richtext' ? 'Rich text (HTML)' : (field.placeholder ?? '')}
								onInput={(v: string) => setField(field, v)}
							/>
						{/if}
					</FormField>
				{/each}
			{:else}
				{#each schema.styleFields as field (field.key)}
					<FormField label={String(field.label ?? field.key)}>
						{#if field.type === 'select'}
							<SelectInput
								value={String(fieldValue(field) ?? '')}
								options={(field.options ?? []) as any}
								onChange={(v: string) => setField(field, v)}
							/>
						{:else if field.type === 'number'}
							<TextInput type="number" value={String(fieldValue(field) ?? '')} onInput={(v: string) => setField(field, Number(v))} />
						{:else}
							<TextInput value={String(fieldValue(field) ?? '')} onInput={(v: string) => setField(field, v)} />
						{/if}
					</FormField>
				{/each}
			{/if}
		</div>
	</Popover>
{/if}

<style>
	.iikiti-inspector { max-width: 360px; max-height: 80vh; overflow: auto; }
	.iikiti-inspector__title { margin: 0 0 8px; font-weight: 600; }
	.iikiti-inspector__tabs { display: flex; gap: 4px; margin-bottom: 8px; }
	.iikiti-inspector__tabs button.selected { text-decoration: underline; font-weight: 600; }
</style>
