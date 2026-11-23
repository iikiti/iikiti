<script lang="ts">
	let {
		node,
		widgetClass,
		elementId = '',
		targetStyle = '',
		iconSvgs = {},
		iconGlyphs = {},
	}: {
		node: { content?: Record<string, unknown> };
		widgetClass: string;
		elementId?: string;
		targetStyle?: string;
		iconSvgs?: Record<string, string>;
		iconGlyphs?: Record<string, string>;
	} = $props();

	function iconRootMarkup(markup: string, name: string, style: string, blockClass: string, id: string): string {
		const openingTag = markup.match(/^\s*<([a-z][a-z0-9-]*)\b[^>]*>/i);
		if (!openingTag) return markup;

		let decorated = openingTag[0];
		const escapeAttribute = (value: string) => value.replace(/[&"<>]/g, (character) => ({
			'&': '&amp;', '"': '&quot;', '<': '&lt;', '>': '&gt;',
		}[character] ?? character));
		const classAttribute = decorated.match(/\sclass="([^"]*)"/i);
		if (classAttribute) {
			decorated = decorated.replace(classAttribute[0], ` class="${classAttribute[1]} iikiti-icon-block ${escapeAttribute(blockClass)}"`);
		} else {
			decorated = decorated.replace(/>$/, ` class="iikiti-icon-block ${escapeAttribute(blockClass)}">`);
		}
		decorated = decorated.replace(/>$/, ` data-block-icon="${escapeAttribute(name)}"${id ? ` id="${escapeAttribute(id)}"` : ''}${style ? ` style="${escapeAttribute(style)}"` : ''}>`);

		return markup.replace(openingTag[0], decorated);
	}

	const iconRef = $derived(String(node.content?.name ?? ''));
	const iconSourceMap = $derived(node.content?.renderer === 'font' ? iconGlyphs : iconSvgs);
	const rawMarkup = $derived(Object.hasOwn(iconSourceMap, iconRef) ? iconSourceMap[iconRef] : '');
</script>

{@html iconRootMarkup(rawMarkup, iconRef, targetStyle, widgetClass, elementId)}
