<script lang="ts">
	import { onMount } from 'svelte';
	import { blockTypes } from './state';
	import Popover from '$components/Popover.svelte';

	export interface Props {
		allowedTypes: string[];
		anchor: HTMLElement | null;
		onClose: () => void;
		onSelect: (type: string) => void;
	}

	let { allowedTypes, anchor, onClose, onSelect }: Props = $props();

	onMount(() => {
		const onKeydown = (e: KeyboardEvent) => {
			if (e.key === 'Escape') {
				e.preventDefault();
				e.stopPropagation();
				onClose();
			}
		};
		document.addEventListener('keydown', onKeydown, true);
		return () => document.removeEventListener('keydown', onKeydown, true);
	});

	function handleClick(type: string, ev: MouseEvent) {
		ev.preventDefault();
		ev.stopPropagation();
		onSelect(type);
	}

	const visibleTypes = (bt: Record<string, Record<string, unknown>>) => {
		const allowed = allowedTypes.length > 0 ? new Set(allowedTypes) : null;
		const list = Object.values(bt).filter((t) => !allowed || allowed.has(String(t.type)));
		const categoryOrder: Record<string, number> = { layout: 0, text: 1, media: 2, content: 3 };
		return list.sort((a, b) => {
			const ca = categoryOrder[String(a.category ?? '')] ?? 99;
			const cb = categoryOrder[String(b.category ?? '')] ?? 99;
			if (ca !== cb) return ca - cb;
			return String(a.label ?? a.type).localeCompare(String(b.label ?? b.type));
		});
	};

	const grouped = $derived.by(() => {
		const bt = $blockTypes;
		if (!bt) return [];
		const list = visibleTypes(bt);
		const groups: Record<string, Array<Record<string, unknown>>> = {};
		for (const t of list) {
			const cat = String(t.category ?? 'other');
			(groups[cat] ??= []).push(t);
		}
		return Object.entries(groups);
	});
</script>

{#if anchor}
	<Popover {anchor} placement="bottom-start" closeOnOutside onclose={onClose}>
		<div class="iikiti-block-palette">
			{#each grouped as [cat, types] (cat)}
				<div class="iikiti-block-palette__group">
					<div class="iikiti-block-palette__label">{cat}</div>
					{#each types as t (String(t.type))}
						<button
							class="iikiti-block-palette__item"
							data-block-type={t.type}
							title={String(t.label ?? t.type)}
							onclick={(e) => handleClick(String(t.type), e)}
						>
							<span class="iikiti-block-palette__icon">{String(t.label ?? t.type).charAt(0)}</span>
							<span class="iikiti-block-palette__name">{String(t.label ?? t.type)}</span>
						</button>
					{/each}
				</div>
			{/each}
		</div>
	</Popover>
{/if}

<style>
	.iikiti-block-palette {
		display: flex;
		flex-direction: column;
		gap: 6px;
		max-height: 320px;
		overflow-y: auto;
		padding: 4px;
	}
	.iikiti-block-palette__group { display: flex; flex-direction: column; gap: 2px; }
	.iikiti-block-palette__label {
		font-size: 10px;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		color: #6b7280;
		padding: 2px 6px;
		font-weight: 600;
	}
	.iikiti-block-palette__item {
		display: flex;
		align-items: center;
		gap: 6px;
		padding: 4px 8px;
		font-size: 13px;
		text-align: left;
		border: 1px solid #d1d5db;
		border-radius: 3px;
		background: #f9fafb;
		color: #111827;
		cursor: pointer;
		transition: background 0.1s, border-color 0.1s;
	}
	.iikiti-block-palette__item:hover,
	.iikiti-block-palette__item:focus {
		background: #ffffff;
		border-color: #93c5fd;
		outline: 2px solid #3b82f6;
		outline-offset: -2px;
	}
	.iikiti-block-palette__icon {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 20px;
		height: 20px;
		border-radius: 3px;
		font-size: 11px;
		font-weight: 700;
		background: #e5e7eb;
		color: #374151;
	}
	.iikiti-block-palette__name { flex: 1; }
</style>
