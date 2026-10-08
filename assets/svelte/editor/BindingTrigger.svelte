<script lang="ts">
	import { onMount } from 'svelte';
	import { apiBase, apiToken, updateNode, queryAncestor } from './state';
	import type { BlockNode } from './state';
	import Popover from '$components/Popover.svelte';
	import Icon from '$components/Icon.svelte';

	/**
	 * Core "query binding" field decorator: a database icon in the corner of a
	 * sidebar field control, offered when the selected block sits inside a
	 * `query` block. Click (or long-press on touch) opens a picker of mappable
	 * result fields — reflected object/property/related fields plus
	 * plugin-registered field functions, served by `GET /api/editor/query-fields`.
	 * Selecting one stores `node.bindings.<fieldKey> = <spec>`; the renderer
	 * resolves it per query result at render time.
	 */
	interface Props {
		node: BlockNode;
		fieldKey: string;
	}

	let { node, fieldKey }: Props = $props();

	let anchor: HTMLElement | null = $state(null);
	let longPressTimer: ReturnType<typeof setTimeout> | null = null;
	let longPressFired = false;

	type FieldSpec = { key: string; label: string; group: string; kind: string };

	const fieldsCache = new Map<string, FieldSpec[]>();
	let fields = $state<FieldSpec[]>([]);
	let loading = $state(false);

	const bound = $derived(node.bindings?.[fieldKey] ?? null);

	const grouped = $derived.by(() => {
		const groups: Record<string, FieldSpec[]> = {};
		for (const f of fields) {
			(groups[f.group ?? 'Other'] ??= []).push(f);
		}
		return Object.entries(groups);
	});

	async function loadFields() {
		const query = queryAncestor(node);
		const objectType = String((query?.content as Record<string, unknown> | undefined)?.objectType ?? '');
		const key = objectType;
		if (fieldsCache.has(key)) {
			fields = fieldsCache.get(key) ?? [];
			return;
		}
		loading = true;
		try {
			const token = $apiToken;
			const res = await fetch(
				`${$apiBase}/editor/query-fields?objectType=${encodeURIComponent(objectType)}`,
				{ credentials: 'same-origin', headers: token ? { 'X-AUTH-TOKEN': token } : {} },
			);
			const data = (await res.json().catch(() => ({ fields: [] }))) as { fields?: FieldSpec[] };
			fields = data.fields ?? [];
			fieldsCache.set(key, fields);
		} finally {
			loading = false;
		}
	}

	function open() {
		void loadFields();
		anchor = anchorEl;
	}

	function onPointerDown() {
		longPressFired = false;
		longPressTimer = setTimeout(() => {
			longPressFired = true;
			open();
		}, 450);
	}

	function onPointerUp() {
		if (longPressTimer) {
			clearTimeout(longPressTimer);
			longPressTimer = null;
		}
	}

	function onClick(ev: MouseEvent) {
		ev.stopPropagation();
		ev.preventDefault();
		if (longPressFired) {
			longPressFired = false;
			return; // long-press already opened the picker
		}
		open();
	}

	function setBinding(spec: string | null) {
		const bindings = { ...(node.bindings ?? {}) };
		if (spec === null) delete bindings[fieldKey];
		else bindings[fieldKey] = spec;
		updateNode(node.id, { bindings });
		anchor = null;
	}

	let anchorEl = $state<HTMLElement | null>(null);

	onMount(
		() =>
			() => {
				if (longPressTimer) clearTimeout(longPressTimer);
			},
	);
</script>

<button
	type="button"
	class="iikiti-binding-trigger"
	class:active={Boolean(bound)}
	title={bound ? `Bound to ${bound} — click to change` : 'Bind to query result field'}
	aria-label="Bind to query result field"
	bind:this={anchorEl}
	onpointerdown={onPointerDown}
	onpointerup={onPointerUp}
	onpointerleave={onPointerUp}
	onclick={onClick}
>
	<Icon name="database" size={12} />
</button>

{#if anchor}
	<Popover {anchor} placement="bottom-end" closeOnOutside portal onclose={() => (anchor = null)}>
		<div class="iikiti-binding-picker" role="listbox" aria-label="Query result fields">
			<div class="iikiti-binding-picker__header">
				<span>Bind “{fieldKey}” to query field</span>
				{#if bound}
					<button type="button" class="iikiti-binding-picker__unbind" onclick={() => setBinding(null)}>
						Remove binding
					</button>
				{/if}
			</div>
			{#if loading && fields.length === 0}
				<p class="iikiti-binding-picker__empty">Loading fields…</p>
			{:else if grouped.length === 0}
				<p class="iikiti-binding-picker__empty">No mappable fields for this query.</p>
			{/if}
			{#each grouped as [group, groupFields] (group)}
				<div class="iikiti-binding-picker__group">
					<div class="iikiti-binding-picker__label">{group}</div>
					{#each groupFields as f (f.key)}
						<button
							type="button"
							class="iikiti-binding-picker__item"
							class:bound={bound === f.key}
							onclick={() => setBinding(f.key)}
						>
							<span class="iikiti-binding-picker__name">{f.label}</span>
							<code class="iikiti-binding-picker__spec">{f.key}</code>
						</button>
					{/each}
				</div>
			{/each}
		</div>
	</Popover>
{/if}

<style>
	.iikiti-binding-trigger {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 28px;
		height: 28px;
		padding: 0;
		border: none;
		border-radius: 4px;
		background: transparent;
		color: inherit;
		cursor: pointer;
	}
	.iikiti-binding-trigger:hover,
	.iikiti-binding-trigger.active {
		color: var(--ik-accent, #a6613c);
		background: color-mix(in srgb, var(--ik-accent, #a6613c) 14%, transparent);
	}
	.iikiti-binding-picker {
		display: flex;
		flex-direction: column;
		gap: 4px;
		width: 240px;
		max-height: 280px;
		overflow-y: auto;
		padding: 4px;
	}
	.iikiti-binding-picker__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 6px;
		padding: 2px 4px 6px;
		font-size: 1rem;
		font-weight: 600;
	}
	.iikiti-binding-picker__unbind {
		border: none;
		background: transparent;
		font-size: 1rem;
		color: var(--ik-danger, #b91c1c);
		cursor: pointer;
		padding: 2px 4px;
		border-radius: 4px;
	}
	.iikiti-binding-picker__unbind:hover {
		background: color-mix(in srgb, var(--ik-danger, #b91c1c) 12%, transparent);
	}
	.iikiti-binding-picker__empty {
		margin: 2px 4px;
		font-size: 1rem;
		color: var(--ik-panel-text-muted, #6b7280);
	}
	.iikiti-binding-picker__group {
		display: flex;
		flex-direction: column;
		gap: 1px;
	}
	.iikiti-binding-picker__label {
		font-size: 1rem;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		color: var(--ik-panel-text-muted, #6b7280);
		padding: 3px 4px;
	}
	.iikiti-binding-picker__item {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 6px;
		padding: 4px 6px;
		font: inherit;
		font-size: 1rem;
		text-align: left;
		border: none;
		border-radius: 4px;
		background: transparent;
		color: var(--ik-panel-text, #111827);
		cursor: pointer;
	}
	.iikiti-binding-picker__item:hover,
	.iikiti-binding-picker__item:focus-visible {
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 8%, transparent);
	}
	.iikiti-binding-picker__item.bound {
		color: var(--ik-accent, #a6613c);
	}
	.iikiti-binding-picker__name {
		flex: 1;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	.iikiti-binding-picker__spec {
		font-size: 1rem;
		color: var(--ik-panel-text-muted, #6b7280);
	}
</style>
