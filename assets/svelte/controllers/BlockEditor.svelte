<script module>
	// --- Types ---
	export interface BlockDefinition {
		type: string;
		label: string;
		acceptsChildren: boolean;
		allowedChildTypes?: string[];
	}

	export interface RegionDefinition {
		id: string;
		name: string;
		allowedBlockTypes: string[];
	}

	export interface Block {
		id: string;
		type: string;
		content: string; // Simplified for this editor
		children: Block[];
	}

	export type EditorData = Record<string, Block[]>;
</script>

<script lang="ts">
	import { onMount } from 'svelte';

	interface Context {
		type: 'region' | 'block';
		id: string;
	}

	// --- Props ---
	interface Props {
		regionDefinitions?: RegionDefinition[];
		blockDefinitions?: BlockDefinition[];
		initialData?: EditorData;
	}

	// Default Definitions
	const defaultRegionDefs: RegionDefinition[] = [
		{ id: 'main', name: 'Main Content', allowedBlockTypes: ['hero', 'container', 'text'] },
		{ id: 'sidebar', name: 'Sidebar', allowedBlockTypes: ['text', 'button'] }
	];

	const defaultBlockDefs: BlockDefinition[] = [
		{ type: 'hero', label: 'Hero Section', acceptsChildren: true, allowedChildTypes: ['text', 'button'] },
		{ type: 'container', label: 'Container', acceptsChildren: true, allowedChildTypes: ['text', 'button', 'container'] },
		{ type: 'text', label: 'Text', acceptsChildren: false },
		{ type: 'button', label: 'Button', acceptsChildren: false }
	];

	const defaultInitialData: EditorData = {
		'main': [
			{ id: 'b1', type: 'hero', content: 'Hero Section', children: [
				{ id: 'b2', type: 'text', content: 'Hero Text', children: [] }
			]},
			{ id: 'b3', type: 'container', content: 'Wrapper', children: [
                { id: 'b5', type: 'text', content: 'Inside Container', children: [] }
            ]}
		],
		'sidebar': [
			{ id: 'b4', type: 'text', content: 'Sidebar Item', children: [] }
		]
	};

	let { 
		regionDefinitions = defaultRegionDefs, 
		blockDefinitions = defaultBlockDefs, 
		initialData = defaultInitialData 
	}: Props = $props();

	// --- State ---
	// Initialize reactive state from props
	let regionData = $state<EditorData>((() => {
		const data = { ...initialData };
		// Ensure all regions exist
		for (const def of regionDefinitions) {
			if (!data[def.id]) {
				data[def.id] = [];
			}
		}
		return data;
	})());

	let draggingId = $state<string | null>(null);
	let dropTarget = $state<{ id: string; pos: 'top' | 'bottom' | 'inside' } | null>(null);

	// --- Helpers ---
	function generateId(): string {
		return 'blk_' + Math.random().toString(36).slice(2, 11);
	}

	function getBlockDef(type: string): BlockDefinition | undefined {
		return blockDefinitions.find(d => d.type === type);
	}

	function findBlock(
		id: string
	): { block: Block; parentArray: Block[]; index: number; regionId: string } | null {
		for (const regionId of Object.keys(regionData)) {
			const blocks = regionData[regionId];
			const result = findInArray(blocks, id);
			if (result) return { ...result, regionId };
		}
		return null;
	}

	function findInArray(
		blocks: Block[], 
		id: string
	): { block: Block; parentArray: Block[]; index: number } | null {
		for (let i = 0; i < blocks.length; i++) {
			if (blocks[i].id === id) {
				return { block: blocks[i], parentArray: blocks, index: i };
			}
			const found = findInArray(blocks[i].children, id);
			if (found) return found;
		}
		return null;
	}

	function isDescendant(sourceId: string, targetId: string): boolean {
		if (sourceId === targetId) return true;
		const source = findBlock(sourceId);
		if (!source) return false;
		
		function check(blocks: Block[]): boolean {
			for (const b of blocks) {
				if (b.id === targetId) return true;
				if (check(b.children)) return true;
			}
			return false;
		}
		return check(source.block.children);
	}

	function isAllowed(blockType: string, context: Context): boolean {
		if (context.type === 'region') {
			const regionDef = regionDefinitions.find(r => r.id === context.id);
			return regionDef ? regionDef.allowedBlockTypes.includes(blockType) : false;
		} else {
			// context.id is the parent block ID
			const parentInfo = findBlock(context.id);
			if (!parentInfo) return false;
			const parentDef = getBlockDef(parentInfo.block.type);
			return parentDef && parentDef.acceptsChildren ? (parentDef.allowedChildTypes?.includes(blockType) ?? false) : false;
		}
	}

	function isAllowedSibling(blockType: string, siblingId: string): boolean {
		const siblingInfo = findBlock(siblingId);
		if (!siblingInfo) return false;
		
		// We need to check the container of the sibling
		// Since we don't have direct parent reference, we infer context from where the sibling was found
		
		// If sibling is at root of a region
		if (regionData[siblingInfo.regionId].includes(siblingInfo.block)) {
			 return isAllowed(blockType, { type: 'region', id: siblingInfo.regionId });
		} else {
			// Sibling is a child of another block. We need to find that parent.
			const parent = findParentBlock(siblingId);
			if (parent) {
				 return isAllowed(blockType, { type: 'block', id: parent.id });
			}
		}
		return false;
	}

	function findParentBlock(childId: string): Block | null {
		for (const regionId of Object.keys(regionData)) {
			const result = findParentInArray(regionData[regionId], childId);
			if (result) return result;
		}
		return null;
	}

	function findParentInArray(blocks: Block[], childId: string): Block | null {
		for (const block of blocks) {
			if (block.children.some(c => c.id === childId)) {
				return block;
			}
			const found = findParentInArray(block.children, childId);
			if (found) return found;
		}
		return null;
	}

	function getAddableTypes(context: Context): BlockDefinition[] {
		let allowed: string[] = [];
		if (context.type === 'region') {
			const r = regionDefinitions.find(x => x.id === context.id);
			allowed = r?.allowedBlockTypes || [];
		} else {
			const bInfo = findBlock(context.id);
			if (bInfo) {
				 const def = getBlockDef(bInfo.block.type);
				 allowed = def?.allowedChildTypes || [];
			}
		}
		return blockDefinitions.filter(d => allowed.includes(d.type));
	}

	// --- Actions ---
	function addBlock(parentArray: Block[], type: string) {
		const def = getBlockDef(type);
		parentArray.push({
			id: generateId(),
			type,
			content: def?.label || type,
			children: []
		});
	}

	function removeBlock(id: string) {
		const info = findBlock(id);
		if (info) {
			info.parentArray.splice(info.index, 1);
		}
	}

	// --- Drag Handlers ---
	function onDragStart(e: DragEvent, id: string) {
		draggingId = id;
		if (e.dataTransfer) {
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData('text/plain', id);
		}
		e.stopPropagation();
	}

	function onDragOver(e: DragEvent, targetId: string, targetType: string, parentContext: Context) {
		e.preventDefault();
		e.stopPropagation();
		if (!draggingId || draggingId === targetId) return;
		if (isDescendant(draggingId, targetId)) return;

		const draggingBlockInfo = findBlock(draggingId);
		if (!draggingBlockInfo) return;
		const draggingType = draggingBlockInfo.block.type;

		const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
		const targetDef = getBlockDef(targetType);
		
		if (targetDef?.acceptsChildren) {
			// Container logic
			const h = rect.height;
			const y = e.clientY - rect.top;
			
			let zone: 'top' | 'bottom' | 'inside' = 'inside';
			
			if (y < h * 0.25) zone = 'top';
			else if (y > h * 0.75) zone = 'bottom';
			else zone = 'inside';

			// Validate Zone
			if (zone === 'inside') {
				if (!isAllowed(draggingType, { type: 'block', id: targetId })) {
					// Fallback to sibling drop if allowed
					if (isAllowedSibling(draggingType, targetId)) {
						 zone = y < h * 0.5 ? 'top' : 'bottom';
					} else {
						return; // Invalid
					}
				}
			} else {
				if (!isAllowedSibling(draggingType, targetId)) {
					 // Fallback to inside if allowed
					 if (isAllowed(draggingType, { type: 'block', id: targetId })) {
						 zone = 'inside';
					 } else {
						 return; // Invalid
					 }
				}
			}

			dropTarget = { id: targetId, pos: zone };

		} else {
			// Simple block
			if (!isAllowedSibling(draggingType, targetId)) return;

			const midY = rect.top + rect.height / 2;
			if (e.clientY < midY) {
				dropTarget = { id: targetId, pos: 'top' };
			} else {
				dropTarget = { id: targetId, pos: 'bottom' };
			}
		}
	}

	function onDrop(e: DragEvent, targetId: string) {
		e.preventDefault();
		e.stopPropagation();
		if (!draggingId || !dropTarget) return;
		
		const sourceId = draggingId;
		const { id: targetBlockId, pos } = dropTarget;
		
		// Reset state
		draggingId = null;
		dropTarget = null;

		if (sourceId === targetBlockId) return;
		if (isDescendant(sourceId, targetBlockId)) return;

		const sourceInfo = findBlock(sourceId);
		if (!sourceInfo) return;

		// Remove from old
		const [movedBlock] = sourceInfo.parentArray.splice(sourceInfo.index, 1);

		// Insert at new
		if (pos === 'inside') {
			const targetInfo = findBlock(targetBlockId);
			if (targetInfo) {
				targetInfo.block.children.push(movedBlock);
			} else {
				// Fallback: put back
				sourceInfo.parentArray.splice(sourceInfo.index, 0, movedBlock);
			}
		} else {
			// Re-find target because indices might have shifted
			const targetInfo = findBlock(targetBlockId);
			if (targetInfo) {
				const insertIndex = pos === 'top' ? targetInfo.index : targetInfo.index + 1;
				targetInfo.parentArray.splice(insertIndex, 0, movedBlock);
			} else {
				 // Fallback
				 sourceInfo.parentArray.splice(sourceInfo.index, 0, movedBlock);
			}
		}
	}
	
	function onDropList(e: DragEvent, list: Block[], context: Context) {
		e.preventDefault();
		e.stopPropagation();
		if (!draggingId) return;
		
		// Only handle if list is empty or we are dropping in the "empty space"
		if (list.length > 0) return;
		
		const sourceId = draggingId;
		draggingId = null;
		dropTarget = null;
		
		const sourceInfo = findBlock(sourceId);
		if (!sourceInfo) return;
		const blockType = sourceInfo.block.type;

		if (!isAllowed(blockType, context)) return;

		if (context.type === 'block' && context.id === sourceId) return;
		if (context.type === 'block' && isDescendant(sourceId, context.id)) return;

		const [movedBlock] = sourceInfo.parentArray.splice(sourceInfo.index, 1);
		list.push(movedBlock);
	}

	onMount(() => {
		console.log('BlockEditor Mounted');
	});
</script>

<div class="block-editor">
	{#each regionDefinitions as region (region.id)}
		<div class="region">
			<div class="region-header">{region.name}</div>
			{@render blockList(regionData[region.id], { type: 'region', id: region.id })}
		</div>
	{/each}
</div>

{#snippet blockList(blocks: Block[], context: Context)}
	<div 
		class="block-list" 
		class:empty={blocks.length === 0}
		ondragover={(e) => {
			if (blocks.length === 0) e.preventDefault();
		}}
		ondrop={(e) => onDropList(e, blocks, context)}
		role="list"
	>
		{#each blocks as block (block.id)}
			{@render blockItem(block, context)}
		{/each}
		
		<div class="add-controls">
			{#each getAddableTypes(context) as type}
				 <button class="btn-add" onclick={() => addBlock(blocks, type.type)}>+ {type.label}</button>
			{/each}
		</div>
	</div>
{/snippet}

{#snippet blockItem(block: Block, parentContext: Context)}
	<!-- Drop Indicator Top -->
	{#if dropTarget?.id === block.id && dropTarget.pos === 'top'}
		<div class="drop-indicator"></div>
	{/if}

	<div 
		class="block"
		class:is-container={getBlockDef(block.type)?.acceptsChildren}
		class:dragging={draggingId === block.id}
		class:drop-inside={dropTarget?.id === block.id && dropTarget.pos === 'inside'}
		draggable="true"
		ondragstart={(e) => onDragStart(e, block.id)}
		ondragover={(e) => onDragOver(e, block.id, block.type, parentContext)}
		ondrop={(e) => onDrop(e, block.id)}
		role="listitem"
	>
		<div class="block-header">
			<span class="drag-handle">⣿</span>
			<span class="block-type">{getBlockDef(block.type)?.label || block.type}</span>
			<input type="text" bind:value={block.content} class="block-input" />
			<button class="btn-icon" onclick={() => removeBlock(block.id)} aria-label="Remove">×</button>
		</div>
		
		{#if getBlockDef(block.type)?.acceptsChildren}
			<div class="block-children">
				{@render blockList(block.children, { type: 'block', id: block.id })}
			</div>
		{/if}
	</div>

	<!-- Drop Indicator Bottom -->
	{#if dropTarget?.id === block.id && dropTarget.pos === 'bottom'}
		<div class="drop-indicator"></div>
	{/if}
{/snippet}

<style>
	.block-editor {
		display: flex;
		flex-direction: column;
		gap: 1.5rem;
		padding: 1rem;
		background: #f8fafc;
		border-radius: 0.5rem;
		font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
	}
	
	.region {
		background: #fff;
		border: 1px solid #e2e8f0;
		border-radius: 0.5rem;
		padding: 1rem;
		box-shadow: 0 1px 2px rgba(0,0,0,0.05);
	}
	
	.region-header {
		font-weight: 600;
		color: #475569;
		margin-bottom: 1rem;
		text-transform: uppercase;
		font-size: 0.875rem;
		letter-spacing: 0.05em;
	}

	.block-list {
		display: flex;
		flex-direction: column;
		gap: 0.5rem;
		min-height: 2rem;
	}
	
	.block-list.empty {
		background: #f1f5f9;
		border: 2px dashed #cbd5e1;
		border-radius: 0.375rem;
		padding: 1rem;
		justify-content: center;
		align-items: center;
	}

	.block {
		background: #fff;
		border: 1px solid #cbd5e1;
		border-radius: 0.375rem;
		padding: 0.5rem;
		transition: all 0.2s;
		position: relative;
	}
	
	.block.is-container {
		background: #f8fafc;
		border-color: #94a3b8;
	}

	.block.dragging {
		opacity: 0.4;
		background: #e2e8f0;
	}
	
	.block.drop-inside {
		background: #eff6ff;
		border-color: #3b82f6;
		box-shadow: inset 0 0 0 2px #3b82f6;
	}

	.block-header {
		display: flex;
		align-items: center;
		gap: 0.5rem;
		margin-bottom: 0.25rem;
	}
	
	.drag-handle {
		cursor: grab;
		color: #94a3b8;
		font-size: 1.25rem;
		line-height: 1;
	}
	
	.block-type {
		font-size: 0.75rem;
		font-weight: 600;
		color: #64748b;
		text-transform: uppercase;
		background: #e2e8f0;
		padding: 0.125rem 0.375rem;
		border-radius: 0.25rem;
		white-space: nowrap;
	}
	
	.block-input {
		flex: 1;
		border: 1px solid transparent;
		background: transparent;
		padding: 0.25rem;
		font-size: 0.875rem;
		border-radius: 0.25rem;
		min-width: 0;
	}
	
	.block-input:focus {
		border-color: #3b82f6;
		background: #fff;
		outline: none;
	}
	
	.btn-icon {
		background: none;
		border: none;
		color: #94a3b8;
		cursor: pointer;
		font-size: 1.25rem;
		padding: 0 0.25rem;
	}
	
	.btn-icon:hover {
		color: #ef4444;
	}

	.block-children {
		margin-top: 0.5rem;
		padding-left: 1.5rem;
		border-left: 2px solid #e2e8f0;
	}

	.add-controls {
		display: flex;
		gap: 0.5rem;
		margin-top: 0.5rem;
		flex-wrap: wrap;
	}
	
	.btn-add {
		background: #fff;
		border: 1px dashed #cbd5e1;
		color: #64748b;
		padding: 0.375rem 0.75rem;
		border-radius: 0.375rem;
		font-size: 0.75rem;
		cursor: pointer;
		transition: all 0.2s;
	}
	
	.btn-add:hover {
		border-color: #3b82f6;
		color: #3b82f6;
		background: #eff6ff;
	}

	.drop-indicator {
		height: 4px;
		background: #3b82f6;
		border-radius: 2px;
		margin: 2px 0;
	}
</style>