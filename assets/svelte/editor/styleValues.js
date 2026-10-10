const KNOWN_PROPERTIES = new Set([
	'display', 'flex-direction', 'justify-content', 'align-items', 'gap', 'row-gap', 'column-gap',
	'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'aspect-ratio',
	'margin', 'padding', 'color', 'font-size', 'font-weight', 'line-height', 'text-align',
	'background-color', 'border-width', 'border-style', 'border-color', 'border-radius', 'opacity',
	'box-shadow', 'position', 'top', 'right', 'bottom', 'left', 'z-index', 'columns',
]);
const UNITLESS_PROPERTIES = new Set(['opacity', 'font-weight', 'line-height', 'z-index']);
const LENGTH_PROPERTIES = new Set([
	'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'margin', 'padding', 'gap',
	'row-gap', 'column-gap', 'font-size', 'border-width', 'border-radius', 'top', 'right', 'bottom', 'left',
]);

function kebabCase(value) {
	return value.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`).toLowerCase();
}

function normalizeProperty(key, type) {
	if (key === 'layout') return 'display';
	if (key === 'align') return type === 'container' ? 'justify-content' : 'text-align';
	if (key === 'size' && type === 'icon') return 'font-size';
	return kebabCase(key);
}

function normalizeValue(property, value) {
	if (typeof value === 'number') {
		if (!Number.isFinite(value)) return null;
		if (property === 'opacity' && (value < 0 || value > 1)) return null;
		return UNITLESS_PROPERTIES.has(property) || value === 0 ? String(value) : `${value}px`;
	}
	if (typeof value !== 'string') return null;
	let normalized = value.trim();
	if (!normalized || /[;{}<>\\"'\u0000-\u001f\u007f]/.test(normalized)) return null;
	if (/\b(?:url|expression|var|attr)\s*\(/i.test(normalized)) return null;
	if (property === 'aspect-ratio') {
		if (normalized === 'original') return null;
		normalized = normalized.replace(/^(\d+)\s*:\s*(\d+)$/, '$1 / $2');
	}
	if (property === 'justify-content' && normalized === 'start') normalized = 'flex-start';
	if (property === 'justify-content' && normalized === 'end') normalized = 'flex-end';
	if (LENGTH_PROPERTIES.has(property) && /^[+-]?(?:\d+\.?\d*|\.\d+)$/.test(normalized)) normalized += 'px';
	if (['color', 'background-color', 'border-color'].includes(property)) {
		if (!/^(?:#[0-9a-f]{3,8}|[a-z]+|(?:rgb|rgba|hsl|hsla)\([0-9a-z.,%/\s+-]+\))$/i.test(normalized)) return null;
	} else if (!/^[a-zA-Z0-9#.%(),/:+\s_-]+$/.test(normalized)) {
		return null;
	}
	return normalized;
}

function fieldPropertyMap(fields) {
	const properties = new Map();
	for (const field of fields ?? []) {
		if (typeof field?.key !== 'string') continue;
		const property = typeof field.cssProperty === 'string' ? field.cssProperty : normalizeProperty(field.key, '');
		if (/^[a-z][a-z0-9-]*$/.test(property)) properties.set(field.key, property);
	}
	return properties;
}

export function styleDeclarations(layer, type = '', fields = []) {
	if (!layer || typeof layer !== 'object' || Array.isArray(layer)) return [];
	const schemaProperties = fieldPropertyMap(fields);
	const declarations = [];
	for (const [key, value] of Object.entries(layer)) {
		let property = normalizeProperty(key, type);
		if (!KNOWN_PROPERTIES.has(property) && !schemaProperties.has(key)) continue;
		if (schemaProperties.has(key)) property = schemaProperties.get(key);
		if (!/^[a-z][a-z0-9-]*$/.test(property)) continue;
		if (key === 'size' && type === 'icon') property = 'font-size';
		const normalized = normalizeValue(property, value);
		if (normalized === null) continue;
		declarations.push([property, normalized]);
	}
	return declarations;
}

export function styleText(style, type = '', fields = [], breakpoint = 'base') {
	const layer = style?.[breakpoint];
	return styleDeclarations(layer, type, fields).map(([property, value]) => `${property}:${value}`).join(';');
}

function escapeCssString(value) {
	return String(value)
		.replace(/\\/g, '\\\\')
		.replace(/"/g, '\\"')
		.replace(/</g, '\\3c ')
		.replace(/>/g, '\\3e ')
		.replace(/[\n\r\f]/g, ' ');
}

export function hasResponsiveOverrides(node, fields = [], breakpoints = {}) {
	if (!node?.style || typeof node.style !== 'object') return false;
	return Object.keys(breakpoints).some((id) => id !== 'base' && styleDeclarations(node.style[id], node.type, fields).length > 0);
}

export function responsiveStyleText(node, breakpoints, fields = [], selector = null) {
	if (!node?.id || !node.style || typeof node.style !== 'object') return '';
	const responsiveLayers = Object.entries(breakpoints ?? {})
		.filter(([id, width]) => id !== 'base' && Number.isFinite(Number(width)) && Number(width) >= 0)
		.map(([id, width]) => ({ id, width: Number(width), declarations: styleDeclarations(node.style[id], node.type, fields) }))
		.filter((layer) => layer.declarations.length > 0);
	if (responsiveLayers.length === 0) return '';
	selector ??= `[data-block-id="${escapeCssString(node.id)}"]`;
	const rules = [];
	const baseDeclarations = styleDeclarations(node.style.base, node.type, fields);
	if (baseDeclarations.length > 0) {
		rules.push(`${selector}{${baseDeclarations.map(([property, value]) => `${property}:${value}`).join(';')}}`);
	}
	for (const layer of responsiveLayers) {
		const declarationText = layer.declarations.map(([property, value]) => `${property}:${value}`).join(';');
		rules.push(`@media (min-width:${layer.width}px){${selector}{${declarationText}}}`);
	}
	return rules.join('\n');
}
