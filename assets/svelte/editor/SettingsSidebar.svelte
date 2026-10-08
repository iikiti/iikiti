<script lang="ts">
	import { selected, blockTypes, tree, updateNode, searchNode } from './state';
	import type { BlockNode } from './state';
	import {
		getSections,
		buildSectionNodes,
		resolveFieldControl,
		resolveFieldDecorators,
		sidebarVersion,
		activeSectionId,
		setActiveSection,
	} from './extensions.js';
	import FormField from '$components/FormField.svelte';
	import TextControl from './controls/TextControl.svelte';

	/**
	 * Dockable settings sidebar. Docks via the viewport bars standard (default
	 * left) and renders the Content / Element / Style sections registered through
	 * the extension API — the same API plugins use. `onFlip` cycles the docked
	 * side; the active tab lives in the registry so it survives a remount.
	 */
	interface Props {
		side?: string;
		onFlip?: () => void;
	}

	let { side = 'left', onFlip }: Props = $props();

	const node = $derived.by(() => {
		const id = $selected;
		void $tree; // recompute when the tree mutates
		if (!id) return null;
		return searchNode(id) as BlockNode | null;
	});

	const ctx = $derived.by(() => {
		const n = node;
		if (!n) return null;
		return {
			node: n,
			schema: ($blockTypes[n.type] ?? {}) as Record<string, unknown>,
			blockTypes: $blockTypes,
			update: (patch: Partial<BlockNode>) => updateNode(n.id, patch),
		};
	});

	const sections = $derived.by(() => {
		void $sidebarVersion;
		return getSections();
	});

	const tab = $derived($activeSectionId);

	const nodes = $derived.by(() => {
		void $sidebarVersion;
		void $tree;
		if (!ctx) return [];
		return buildSectionNodes(tab, ctx);
	});

	function fieldValue(n: Record<string, unknown>): unknown {
		if (typeof n.get === 'function') return (n.get as (c: unknown) => unknown)(ctx);
		const key = (n.field as Record<string, unknown>).key as string;
		if (n.path === 'element') return node?.element?.[key];
		if (n.path === 'style') return (node?.style as Record<string, unknown> | undefined)?.base
			? ((node?.style as Record<string, unknown>).base as Record<string, unknown>)[key]
			: undefined;
		return node?.content?.[key];
	}

	function fieldOnChange(n: Record<string, unknown>, value: unknown) {
		if (!node) return;
		if (typeof n.set === 'function') {
			(n.set as (c: unknown, v: unknown) => void)(ctx, value);
			return;
		}
		const key = (n.field as Record<string, unknown>).key as string;
		if (n.path === 'element') {
			updateNode(node.id, { element: { ...(node.element ?? {}), [key]: value } });
		} else if (n.path === 'style') {
			const style = (node.style ?? {}) as Record<string, unknown>;
			const base = (style.base ?? {}) as Record<string, unknown>;
			updateNode(node.id, { style: { ...style, base: { ...base, [key]: value } } });
		} else {
			updateNode(node.id, { content: { ...(node.content ?? {}), [key]: value } });
		}
	}

	function addItem(n: Record<string, unknown>) {
		const items = (n.items as unknown[]) ?? [];
		(n.onChange as (v: unknown[]) => void)([...items, {}]);
	}

	function removeItem(n: Record<string, unknown>, index: number) {
		const items = (n.items as unknown[]) ?? [];
		(n.onChange as (v: unknown[]) => void)(items.filter((_, i) => i !== index));
	}

	function updateItem(n: Record<string, unknown>, index: number, key: string, value: unknown) {
		const items = (n.items as unknown[]) ?? [];
		(n.onChange as (v: unknown[]) => void)(
			items.map((item, i) =>
				i === index ? { ...(item as Record<string, unknown>), [key]: value } : item,
			),
		);
	}
</script>

<div class="iikiti-settings iikiti-settings--{side}" data-tour="editor.settings">
	<div class="iikiti-settings__header">
		<span class="iikiti-settings__title">
			{node ? (ctx?.schema?.label ?? node.type) : 'Settings'}
		</span>
		<button
			type="button"
			class="iikiti-settings__flip"
			title="Dock sidebar on another side (currently {side})"
			aria-label="Flip sidebar"
			onclick={() => onFlip?.()}
		>⇄ {side}</button>
	</div>

	{#if !node}
		<p class="iikiti-settings__empty">Select a block to edit its content, element and style.</p>
	{:else}
		<div class="iikiti-settings__tabs" role="tablist">
			{#each sections as s (s.id)}
				<button
					type="button"
					role="tab"
					aria-selected={tab === s.id}
					class:active={tab === s.id}
					data-tour="sidebar.tab.{s.id}"
					onclick={() => setActiveSection(s.id)}
				>{s.label}</button>
			{/each}
		</div>

		<div class="iikiti-settings__body" role="tabpanel" data-tour="sidebar.section.{tab}">
			{#each nodes as child, i (child.id ?? i)}
				{@render renderNode(child)}
			{/each}
		</div>
	{/if}
</div>

{#snippet renderNode(n: Record<string, unknown>)}
	{#if n.kind === 'field'}
		{@const Control = resolveFieldControl((n.field as Record<string, unknown>).type as string) ?? TextControl}
		{@const fieldKey = (n.field as Record<string, unknown>).key as string}
		{@const decorators = ctx ? resolveFieldDecorators(n, ctx) : []}
		<div class="iikiti-field" data-field-key={fieldKey}>
			<FormField field={n.field as { key: string; label: string; type: string }}>
				<Control
					field={n.field}
					value={fieldValue(n)}
					onChange={(v: unknown) => fieldOnChange(n, v)}
				/>
			</FormField>
			{#each decorators as dec (dec.id)}
				{@const Decorator = dec.component}
				<span class="iikiti-field__decorator">
					<Decorator node={node} fieldNode={n} {fieldKey} />
				</span>
			{/each}
		</div>
	{:else if n.kind === 'group'}
		<section class="iikiti-settings__group" data-tour="sidebar.group.{n.id}">
			<h4 class="iikiti-settings__group-title">{n.header ?? n.label}</h4>
			<div class="iikiti-settings__group-body">
				{#each (n.nodes as Record<string, unknown>[]) ?? [] as sub, i (sub.id ?? i)}
					{@render renderNode(sub)}
				{/each}
			</div>
		</section>
	{:else if n.kind === 'repeater'}
		{@const items = (n.items as unknown[]) ?? []}
		<div class="iikiti-settings__repeater" data-tour="sidebar.repeater.{n.id}">
			{#if items.length === 0}
				<p class="iikiti-settings__hint">No {n.itemLabel ?? 'items'} yet.</p>
			{/if}
			{#each items as item, index (index)}
				<div class="iikiti-settings__repeater-row">
					{#each (n.fields as Record<string, unknown>[]) ?? [] as sub (sub.key)}
						{@const SubControl = resolveFieldControl(sub.type as string) ?? TextControl}
						<label class="iikiti-settings__repeater-field">
							<span class="iikiti-settings__repeater-label">{sub.label}</span>
							<SubControl
								field={sub}
								value={(item as Record<string, unknown>)?.[sub.key as string]}
								onChange={(v: unknown) => updateItem(n, index, sub.key as string, v)}
							/>
						</label>
					{/each}
					<button
						type="button"
						class="iikiti-settings__repeater-remove"
						title="Remove"
						aria-label="Remove {n.itemLabel ?? 'item'}"
						onclick={() => removeItem(n, index)}
					>×</button>
				</div>
			{/each}
			<button type="button" class="iikiti-btn iikiti-btn--sm" onclick={() => addItem(n)}>
				+ {n.addLabel ?? 'Add'}
			</button>
		</div>
	{/if}
{/snippet}

<style>
	/*
	 * The sidebar mirrors the top bar (Toolbar.svelte): same translucent
	 * panel background + blur, same `--ik-*` tokens and fallbacks, same
	 * button metrics (32px hit area, 6px radius, 10% neutral hover tint) so
	 * the two chrome pieces read as one surface. The manager pins the rail
	 * below the top stack; the border faces the canvas (dock-edge).
	 */
	.iikiti-settings {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding: 6px 8px;
		min-height: 100%;
		box-sizing: border-box;
		font-size: 1rem;
		background: color-mix(in srgb, var(--ik-panel-bg, #ffffff) 94%, transparent);
		backdrop-filter: blur(6px);
		color: var(--ik-panel-text, #111827);
	}
	.iikiti-settings--left { border-inline-end: 1px solid var(--ik-panel-border, #e5e7eb); }
	.iikiti-settings--right { border-inline-start: 1px solid var(--ik-panel-border, #e5e7eb); }
	.iikiti-settings--top { border-bottom: 1px solid var(--ik-panel-border, #e5e7eb); }
	.iikiti-settings--bottom { border-top: 1px solid var(--ik-panel-border, #e5e7eb); }
	.iikiti-settings__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		height: 36px;
	}
	.iikiti-settings__title {
		font-weight: 600;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	.iikiti-settings__flip {
		flex-shrink: 0;
		border: none;
		border-radius: 6px;
		background: transparent;
		color: var(--ik-panel-text-muted, #6b7280);
		font-size: 1rem;
		font-weight: 500;
		height: 36px;
		padding: 0 10px;
		cursor: pointer;
		text-transform: capitalize;
		transition:
			background-color 0.12s ease,
			color 0.12s ease;
	}
	.iikiti-settings__flip:hover,
	.iikiti-settings__flip:focus-visible {
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 10%, transparent);
		color: var(--ik-panel-text, #111827);
	}
	.iikiti-settings__tabs {
		display: flex;
		gap: 2px;
		flex-wrap: wrap;
		padding-bottom: 6px;
	}
	.iikiti-settings__tabs button {
		display: inline-flex;
		align-items: center;
		border: none;
		background: transparent;
		color: var(--ik-panel-text-muted, #6b7280);
		font-size: 1rem;
		font-weight: 500;
		height: 36px;
		padding: 0 10px;
		border-radius: 6px;
		cursor: pointer;
		transition:
			background-color 0.12s ease,
			color 0.12s ease;
	}
	.iikiti-settings__tabs button:hover,
	.iikiti-settings__tabs button:focus-visible {
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 10%, transparent);
	}
	.iikiti-settings__tabs button.active {
		background: color-mix(in srgb, var(--ik-panel-text, #111827) 10%, transparent);
		color: var(--ik-panel-text, #111827);
		font-weight: 600;
	}
	.iikiti-settings__body {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}

	/* ── Field decorator affordances (e.g. query field bindings) ──
	 * Relative wrapper so decorators can sit in the input's corner. Revealed
	 * while the field is hovered/focused; always visible on touch, where a
	 * long-press opens the picker immediately. */
	.iikiti-field {
		position: relative;
	}
	.iikiti-field__decorator {
		position: absolute;
		right: 2px;
		bottom: 2px;
		z-index: var(--iikiti-z-editor-controls);
		display: inline-flex;
		opacity: 0;
		pointer-events: none;
		transition: opacity 0.12s ease;
	}
	.iikiti-field:hover .iikiti-field__decorator,
	.iikiti-field:focus-within .iikiti-field__decorator {
		opacity: 1;
		pointer-events: auto;
	}
	:global(.iikiti-touch) .iikiti-field__decorator {
		opacity: 1;
		pointer-events: auto;
	}

	.iikiti-settings__group {
		border: 1px solid var(--ik-panel-border, #e5e7eb);
		border-radius: 8px;
		padding: 8px;
	}
	.iikiti-settings__group-title {
		margin: 0 0 6px;
		font-size: 1rem;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		color: var(--ik-panel-text-muted, #6b7280);
	}
	.iikiti-settings__group-body {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.iikiti-settings__hint,
	.iikiti-settings__empty {
		margin: 0;
		color: var(--ik-panel-text-muted, #6b7280);
		font-size: 1rem;
	}
	.iikiti-settings__empty {
		padding: 12px 4px;
	}
	.iikiti-settings__repeater {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.iikiti-settings__repeater-row {
		display: grid;
		grid-template-columns: 1fr 1fr auto;
		gap: 6px;
		align-items: end;
	}
	.iikiti-settings__repeater-field {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
	.iikiti-settings__repeater-label {
		font-size: 1rem;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		color: var(--ik-panel-text-muted, #6b7280);
	}
	.iikiti-settings__repeater-remove {
		border: 1px solid var(--ik-panel-border, #e5e7eb);
		border-radius: 6px;
		background: transparent;
		color: var(--ik-danger, #b91c1c);
		width: 36px;
		height: 36px;
		cursor: pointer;
		line-height: 1;
	}
	.iikiti-settings__repeater-remove:hover {
		background: color-mix(in srgb, var(--ik-danger, #b91c1c) 12%, transparent);
	}
</style>
