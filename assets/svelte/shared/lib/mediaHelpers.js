/**
 * Pure JavaScript helpers for normalized DAM media values, previews, focal
 * points, file sizes, MIME accept matching, and asset API response parsing.
 *
 * Intentionally dependency-free (no Svelte or DOM imports) so Bun unit tests
 * can import and verify all media logic directly.
 */

/**
 * @typedef {{ x: number, y: number }} FocalPoint
 */

/**
 * @typedef {object} NormalizedMediaValue
 * @property {'asset'|'url'|'empty'} source
 * @property {number|null} assetId
 * @property {string|null} publicToken
 * @property {string} url
 * @property {string|null} previewUrl
 * @property {string|null} thumbnailUrl
 * @property {string|null} mimeType
 * @property {number|null} width
 * @property {number|null} height
 * @property {FocalPoint} focalPoint
 * @property {string|null} dominantColor
 * @property {string} defaultAlt
 */

const DEFAULT_FOCAL_POINT = Object.freeze({ x: 0.5, y: 0.5 });

const EXTENSION_MIME_MAP = Object.freeze({
	'.jpg': 'image/jpeg',
	'.jpeg': 'image/jpeg',
	'.png': 'image/png',
	'.gif': 'image/gif',
	'.webp': 'image/webp',
	'.avif': 'image/avif',
	'.svg': 'image/svg+xml',
	'.ico': 'image/x-icon',
	'.mp4': 'video/mp4',
	'.webm': 'video/webm',
	'.mov': 'video/quicktime',
	'.mp3': 'audio/mpeg',
	'.wav': 'audio/wav',
	'.ogg': 'audio/ogg',
	'.pdf': 'application/pdf',
	'.json': 'application/json',
	'.txt': 'text/plain',
	'.csv': 'text/csv',
});

/**
 * @param {unknown} value
 * @returns {string}
 */
function cleanString(value) {
	return typeof value === 'string' ? value.trim() : '';
}

/**
 * @param {unknown} value
 * @returns {number|null}
 */
function toPositiveInt(value) {
	if (value === null || value === undefined || value === '') return null;
	const num = typeof value === 'number' ? value : Number(value);
	if (!Number.isFinite(num) || num <= 0) return null;
	return Math.round(num);
}

/**
 * Clamp a coordinate value into `[0, 1]`, falling back to `0.5` for invalid input.
 *
 * @param {unknown} value
 * @returns {number}
 */
export function clampUnit(value) {
	if (value === null || value === undefined || value === '') return 0.5;
	const num = typeof value === 'number' ? value : Number(value);
	if (!Number.isFinite(num)) return 0.5;
	return Math.min(1, Math.max(0, num));
}

/**
 * @param {unknown} input
 * @param {unknown} [fallbackX]
 * @param {unknown} [fallbackY]
 * @returns {FocalPoint}
 */
function normalizeFocalPoint(input, fallbackX, fallbackY) {
	if (input && typeof input === 'object' && !Array.isArray(input)) {
		const record = /** @type {Record<string, unknown>} */ (input);
		return {
			x: clampUnit(record.x),
			y: clampUnit(record.y),
		};
	}
	if (fallbackX !== undefined || fallbackY !== undefined) {
		return {
			x: clampUnit(fallbackX),
			y: clampUnit(fallbackY),
		};
	}
	return { x: DEFAULT_FOCAL_POINT.x, y: DEFAULT_FOCAL_POINT.y };
}

/**
 * Extracts a DAM public token from an `/assets/{token}/...` URL when present.
 *
 * @param {unknown} url
 * @returns {string|null}
 */
export function extractPublicTokenFromUrl(url) {
	const raw = cleanString(url);
	if (!raw) return null;
	const match = raw.match(/(?:^|\/)assets\/([A-Za-z0-9_-]{6,})\/[^/?#]+/);
	return match ? match[1] : null;
}

/**
 * Picks the best matching variant URL from an asset's `variants` array.
 *
 * @param {unknown} variants
 * @param {string[]} preferredKeys
 * @returns {string|null}
 */
function pickVariantUrl(variants, preferredKeys) {
	if (!Array.isArray(variants) || variants.length === 0) return null;
	const imageVariants = variants.filter(
		(v) => v && typeof v === 'object' && typeof v.url === 'string' && v.url.trim() !== '',
	);
	if (imageVariants.length === 0) return null;

	for (const key of preferredKeys) {
		const exact = imageVariants.find((v) => String(v.key ?? '').toLowerCase() === key);
		if (exact) return exact.url.trim();
	}
	for (const key of preferredKeys) {
		const partial = imageVariants.find((v) => String(v.key ?? '').toLowerCase().includes(key));
		if (partial) return partial.url.trim();
	}
	return null;
}

/**
 * Normalizes any media field value (null, legacy URL string, DAM asset object,
 * external URL object, or empty block default) into a consistent shape.
 *
 * @param {unknown} value
 * @returns {NormalizedMediaValue}
 */
export function normalizeMediaValue(value) {
	const empty = () => ({
		source: /** @type {'empty'} */ ('empty'),
		assetId: null,
		publicToken: null,
		url: '',
		previewUrl: null,
		thumbnailUrl: null,
		mimeType: null,
		width: null,
		height: null,
		focalPoint: { x: 0.5, y: 0.5 },
		dominantColor: null,
		defaultAlt: '',
	});

	if (value === null || value === undefined) {
		return empty();
	}

	if (typeof value === 'string') {
		const trimmed = value.trim();
		if (!trimmed) return empty();
		return {
			...empty(),
			source: 'url',
			url: trimmed,
		};
	}

	if (typeof value !== 'object' || Array.isArray(value)) {
		return empty();
	}

	const raw = /** @type {Record<string, unknown>} */ (value);
	const declaredSource = cleanString(raw.source).toLowerCase();
	const assetId = toPositiveInt(
		raw.assetId ?? raw.asset_id ?? (declaredSource === 'asset' ? raw.id : null),
	);
	const rawUrl = cleanString(raw.url ?? raw.fileUrl ?? raw.file_url ?? raw.uri);
	const publicToken =
		cleanString(raw.publicToken ?? raw.public_token) ||
		(assetId !== null || declaredSource === 'asset' ? extractPublicTokenFromUrl(rawUrl) : null) ||
		null;
	const url = rawUrl || (publicToken ? `/assets/${publicToken}/original` : '');
	const previewUrl = cleanString(raw.previewUrl ?? raw.preview_url) || null;
	const thumbnailUrl = cleanString(raw.thumbnailUrl ?? raw.thumbnail_url) || null;
	const mimeType = cleanString(raw.mimeType ?? raw.mime_type) || null;
	const width = toPositiveInt(raw.width);
	const height = toPositiveInt(raw.height);
	const focalPoint = normalizeFocalPoint(
		raw.focalPoint ?? raw.focal_point,
		raw.focalX ?? raw.focal_x,
		raw.focalY ?? raw.focal_y,
	);
	const dominantColor = cleanString(raw.dominantColor ?? raw.dominant_color) || null;
	const defaultAlt = typeof (raw.defaultAlt ?? raw.default_alt) === 'string'
		? String(raw.defaultAlt ?? raw.default_alt)
		: '';

	const isAsset =
		declaredSource === 'asset'
			? assetId !== null || publicToken !== null || url !== ''
			: assetId !== null || publicToken !== null;

	/** @type {'asset'|'url'|'empty'} */
	let source = 'empty';
	if (isAsset) {
		source = 'asset';
	} else if (url !== '' || previewUrl !== null || thumbnailUrl !== null) {
		source = 'url';
	}

	if (source === 'empty') {
		return empty();
	}

	return {
		source,
		assetId,
		publicToken,
		url,
		previewUrl,
		thumbnailUrl,
		mimeType,
		width,
		height,
		focalPoint,
		dominantColor,
		defaultAlt,
	};
}

/**
 * Builds a structured DAM asset media value from an API asset DTO.
 *
 * @param {Record<string, unknown> | null | undefined} asset
 */
export function buildAssetMediaValue(asset) {
	const raw = asset && typeof asset === 'object' ? asset : {};
	const assetId = toPositiveInt(raw.assetId ?? raw.asset_id ?? raw.id);
	const rawUrl = cleanString(raw.url ?? raw.fileUrl ?? raw.file_url ?? raw.uri);
	const publicToken =
		cleanString(raw.publicToken ?? raw.public_token) ||
		extractPublicTokenFromUrl(rawUrl) ||
		null;
	const url = rawUrl || (publicToken ? `/assets/${publicToken}/original` : '');

	const explicitPreview = cleanString(raw.previewUrl ?? raw.preview_url);
	const explicitThumb = cleanString(raw.thumbnailUrl ?? raw.thumbnail_url);
	const variantPreview = pickVariantUrl(raw.variants, ['preview', 'medium', 'large', 'card', 'web']);
	const variantThumb = pickVariantUrl(raw.variants, ['thumb', 'thumbnail', 'small', 'preview', 'medium']);

	const previewUrl = explicitPreview || variantPreview || null;
	const thumbnailUrl = explicitThumb || variantThumb || null;

	return {
		source: /** @type {'asset'} */ ('asset'),
		assetId,
		publicToken,
		url,
		previewUrl,
		thumbnailUrl,
		mimeType: cleanString(raw.mimeType ?? raw.mime_type) || null,
		width: toPositiveInt(raw.width),
		height: toPositiveInt(raw.height),
		focalPoint: normalizeFocalPoint(
			raw.focalPoint ?? raw.focal_point,
			raw.focalX ?? raw.focal_x,
			raw.focalY ?? raw.focal_y,
		),
		dominantColor: cleanString(raw.dominantColor ?? raw.dominant_color) || null,
		defaultAlt: typeof (raw.defaultAlt ?? raw.default_alt) === 'string'
			? String(raw.defaultAlt ?? raw.default_alt)
			: '',
	};
}

/**
 * Builds a structured external URL media value.
 *
 * @param {unknown} url
 * @returns {{ source: 'url', url: string }}
 */
export function buildUrlMediaValue(url) {
	return {
		source: 'url',
		url: cleanString(url),
	};
}

/**
 * Resolves the best preview URL for a media value, preferring
 * `previewUrl` -> `thumbnailUrl` -> `url`.
 *
 * @param {unknown} mediaValue
 * @returns {string}
 */
export function resolvePreviewUrl(mediaValue) {
	if (typeof mediaValue === 'string') {
		return mediaValue.trim();
	}
	if (!mediaValue || typeof mediaValue !== 'object') {
		return '';
	}
	const raw = /** @type {Record<string, unknown>} */ (mediaValue);
	const previewUrl = cleanString(raw.previewUrl ?? raw.preview_url);
	if (previewUrl) return previewUrl;
	const thumbnailUrl = cleanString(raw.thumbnailUrl ?? raw.thumbnail_url);
	if (thumbnailUrl) return thumbnailUrl;
	const url = cleanString(raw.url ?? raw.fileUrl ?? raw.file_url ?? raw.uri);
	if (url) return url;
	const token = cleanString(raw.publicToken ?? raw.public_token);
	return token ? `/assets/${token}/original` : '';
}

/**
 * Formats a normalized `{ x, y }` focal point (clamped to `0..1`, rounded to
 * 1 decimal percentage) as a CSS `object-position` value (e.g. `'50% 50%'`).
 *
 * @param {unknown} focalPoint
 * @returns {string}
 */
export function formatObjectPosition(focalPoint) {
	const point = normalizeFocalPoint(focalPoint);
	const formatPercent = (unit) => `${Number((unit * 100).toFixed(1))}%`;
	return `${formatPercent(point.x)} ${formatPercent(point.y)}`;
}

/**
 * Formats a byte count into a human-readable string (e.g. `'1.5 MB'`).
 *
 * @param {unknown} bytes
 * @returns {string}
 */
export function formatBytes(bytes) {
	const num = typeof bytes === 'number' ? bytes : Number(bytes);
	if (!Number.isFinite(num) || num <= 0) return '0 B';
	const units = ['B', 'KB', 'MB', 'GB', 'TB'];
	const exponent = Math.min(Math.floor(Math.log(num) / Math.log(1024)), units.length - 1);
	const value = num / 1024 ** exponent;
	const formatted = exponent === 0 ? String(Math.round(value)) : String(Number(value.toFixed(1)));
	return `${formatted} ${units[exponent]}`;
}

/**
 * Checks whether a MIME type satisfies an HTML `accept`-style filter string
 * (supporting wildcard accept filters, category wildcards like `image/*` or
 * `video/*`, comma-separated lists like `image/jpeg,image/png`, and common
 * file extensions like `.png`).
 *
 * @param {unknown} mimeType
 * @param {unknown} accept
 * @returns {boolean}
 */
export function mimeMatchesAccept(mimeType, accept) {
	const acceptStr = cleanString(accept).toLowerCase();
	if (!acceptStr || acceptStr === '*/*' || acceptStr === '*') {
		return true;
	}

	const normalizedMime = cleanString(mimeType).split(';')[0].trim().toLowerCase();
	if (!normalizedMime) {
		return false;
	}

	const tokens = acceptStr
		.split(',')
		.map((token) => token.trim())
		.filter(Boolean);

	if (tokens.length === 0) {
		return true;
	}

	for (const token of tokens) {
		if (token === '*/*' || token === '*') {
			return true;
		}
		if (token.endsWith('/*')) {
			const prefix = token.slice(0, -1); // includes trailing '/'
			if (normalizedMime.startsWith(prefix)) {
				return true;
			}
			continue;
		}
		if (token.startsWith('.')) {
			const mapped = EXTENSION_MIME_MAP[token];
			if (mapped && normalizedMime === mapped) {
				return true;
			}
			continue;
		}
		if (normalizedMime === token) {
			return true;
		}
	}

	return false;
}

/**
 * Returns true when the given MIME type is an image type.
 *
 * @param {unknown} mimeType
 * @returns {boolean}
 */
export function isImageMimeType(mimeType) {
	return mimeMatchesAccept(mimeType, 'image/*');
}

/**
 * Validates that an external media URL is a well-formed `https://` URL.
 *
 * @param {unknown} url
 * @returns {boolean}
 */
export function isValidHttpsUrl(url) {
	const trimmed = cleanString(url);
	if (!trimmed) return false;
	try {
		const parsed = new URL(trimmed);
		return parsed.protocol === 'https:' && parsed.hostname.length > 0;
	} catch {
		return false;
	}
}

/**
 * Combines `apiBase`, `apiEndpoint`, and an optional suffix without duplicating
 * a leading `/api` segment.
 *
 * @param {string} apiBase
 * @param {string} apiEndpoint
 * @param {string} [suffix]
 * @returns {string}
 */
export function resolveAssetEndpoint(apiBase, apiEndpoint, suffix = '') {
	const endpoint = cleanString(apiEndpoint) || '/api/editor/assets';
	const tail = cleanString(suffix);
	const withSuffix = (base) => `${base.replace(/\/+$/, '')}${tail}`;

	if (/^https?:\/\//i.test(endpoint)) {
		return withSuffix(endpoint);
	}

	const base = cleanString(apiBase).replace(/\/+$/, '');
	if (!base) {
		return withSuffix(endpoint);
	}

	if (endpoint === base || endpoint.startsWith(`${base}/`)) {
		return withSuffix(endpoint);
	}

	if (base.endsWith('/api') && endpoint.startsWith('/api/')) {
		return withSuffix(`${base}${endpoint.slice(4)}`);
	}

	return withSuffix(`${base}${endpoint.startsWith('/') ? '' : '/'}${endpoint}`);
}

/**
 * Extracts a normalized list, pagination metadata, and optional folder/tag
 * facets from `/api/editor/assets` or `/api/admin/assets` responses.
 *
 * @param {unknown} data
 * @returns {{
 *   assets: Record<string, unknown>[],
 *   totalItems: number,
 *   page: number,
 *   itemsPerPage: number,
 *   folders: Array<{ id: string|number, name: string }>,
 *   tags: Array<{ id?: string|number, name: string, slug?: string }>
 * }}
 */
export function parseAssetListResponse(data) {
	const raw = data && typeof data === 'object' ? /** @type {Record<string, unknown>} */ (data) : {};
	let assets = [];
	if (Array.isArray(data)) {
		assets = data;
	} else if (Array.isArray(raw.assets)) {
		assets = raw.assets;
	} else if (Array.isArray(raw.items)) {
		assets = raw.items;
	} else if (Array.isArray(raw['hydra:member'])) {
		assets = raw['hydra:member'];
	} else if (Array.isArray(raw.member)) {
		assets = raw.member;
	}

	const totalRaw = raw.totalItems ?? raw.total ?? raw['hydra:totalItems'];
	const totalItems = Number.isFinite(Number(totalRaw)) ? Number(totalRaw) : assets.length;
	const pageRaw = raw.page ?? raw.currentPage;
	const page = Number.isFinite(Number(pageRaw)) && Number(pageRaw) > 0 ? Number(pageRaw) : 1;
	const perPageRaw = raw.itemsPerPage ?? raw.perPage ?? raw['hydra:itemsPerPage'];
	const itemsPerPage = Number.isFinite(Number(perPageRaw)) && Number(perPageRaw) > 0 ? Number(perPageRaw) : 24;

	const facets = raw.facets && typeof raw.facets === 'object' ? /** @type {Record<string, unknown>} */ (raw.facets) : {};
	const rawFolders = Array.isArray(raw.folders) ? raw.folders : Array.isArray(facets.folders) ? facets.folders : [];
	const rawTags = Array.isArray(raw.tags) ? raw.tags : Array.isArray(facets.tags) ? facets.tags : [];

	const folders = rawFolders
		.filter((f) => f && typeof f === 'object' && (f.id !== undefined || f.name))
		.map((f) => ({ id: f.id ?? f.name, name: String(f.name ?? f.title ?? f.id) }));

	const tags = rawTags
		.map((t) => {
			if (typeof t === 'string') return { name: t, slug: t };
			if (t && typeof t === 'object' && (t.name || t.slug)) {
				return {
					id: t.id,
					name: String(t.name ?? t.slug),
					slug: String(t.slug ?? t.name),
				};
			}
			return null;
		})
		.filter((t) => t !== null);

	return {
		assets,
		totalItems,
		page,
		itemsPerPage,
		folders,
		tags,
	};
}
