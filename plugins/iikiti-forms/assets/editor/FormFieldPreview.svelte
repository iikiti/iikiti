<script lang="ts">
	let {
		node,
		widgetClass,
		elementId = '',
	}: {
		node: { type: string; content?: Record<string, unknown> };
		widgetClass: string;
		elementId?: string;
	} = $props();

	const buttonType = $derived((['button', 'submit'].includes(String(node.content?.type ?? 'button'))
		? String(node.content?.type ?? 'button')
		: 'button') as 'button' | 'submit');
	const inputType = $derived(['text', 'password', 'email', 'number'].includes(String(node.content?.type ?? 'text'))
		? String(node.content?.type ?? 'text')
		: 'text');
</script>

{#if node.type === 'button'}
	<button class="{widgetClass} iikiti-button-preview" id={elementId || undefined} type={buttonType} disabled>{String(node.content?.text ?? 'Button')}</button>
{:else if node.type === 'input'}
	<label class="{widgetClass} iikiti-form-control-preview" id={elementId || undefined}>
		{#if node.content?.label}<span>{node.content.label}</span>{/if}
		<input type={inputType} value={String(node.content?.value ?? '')} disabled />
	</label>
{:else if node.type === 'textarea'}
	<label class="{widgetClass} iikiti-form-control-preview" id={elementId || undefined}>
		{#if node.content?.label}<span>{node.content.label}</span>{/if}
		<textarea rows={Number(node.content?.rows ?? 4) || 4} disabled>{String(node.content?.value ?? '')}</textarea>
	</label>
{:else if node.type === 'select'}
	<label class="{widgetClass} iikiti-form-control-preview" id={elementId || undefined}>
		{#if node.content?.label}<span>{node.content.label}</span>{/if}
		<select disabled>
			{#each Array.isArray(node.content?.options) ? node.content.options : [] as option, index (`${index}-${String(option?.value ?? '')}`)}
				<option value={String(option?.value ?? '')} selected={String(option?.value ?? '') === String(node.content?.value ?? '')}>{String(option?.label ?? option?.value ?? '')}</option>
			{/each}
		</select>
	</label>
{:else if node.type === 'range'}
	<label class="{widgetClass} iikiti-form-control-preview" id={elementId || undefined}>
		{#if node.content?.label}<span>{node.content.label}</span>{/if}
		<input type="range" min={node.content?.min ?? 0} max={node.content?.max ?? 100} step={node.content?.step ?? 1} value={node.content?.value ?? 50} disabled />
	</label>
{:else if node.type === 'checkbox' || node.type === 'radio'}
	<label class="{widgetClass} iikiti-form-control-preview" id={elementId || undefined}>
		<input type={node.type} value={String(node.content?.value ?? '')} checked={Boolean(node.content?.checked)} disabled />
		{#if node.content?.label}<span>{node.content.label}</span>{/if}
	</label>
{:else if node.type === 'legend'}
	<strong class="{widgetClass} iikiti-legend-preview" id={elementId || undefined}>{node.content?.text ?? ''}</strong>
{/if}
