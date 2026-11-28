import { describe, expect, test } from 'bun:test';
import {
	buildAssetMediaValue,
	buildUrlMediaValue,
	formatBytes,
	formatObjectPosition,
	isImageMimeType,
	isValidHttpsUrl,
	mimeMatchesAccept,
	normalizeMediaValue,
	parseAssetListResponse,
	resolveAssetEndpoint,
	resolvePreviewUrl,
} from '../../assets/svelte/shared/lib/mediaHelpers.js';

describe('normalizeMediaValue', () => {
	test('returns empty shape for null, undefined, empty string, and empty defaults', () => {
		const expectedEmpty = {
			source: 'empty',
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
		};

		expect(normalizeMediaValue(null)).toEqual(expectedEmpty);
		expect(normalizeMediaValue(undefined)).toEqual(expectedEmpty);
		expect(normalizeMediaValue('')).toEqual(expectedEmpty);
		expect(normalizeMediaValue('   ')).toEqual(expectedEmpty);
		expect(normalizeMediaValue({})).toEqual(expectedEmpty);
		expect(normalizeMediaValue({ source: 'url', url: '' })).toEqual(expectedEmpty);
		expect(normalizeMediaValue({ source: 'url', url: '   ' })).toEqual(expectedEmpty);
		expect(normalizeMediaValue({ source: 'empty' })).toEqual(expectedEmpty);
		expect(normalizeMediaValue({ source: 'empty', assetId: null, url: '' })).toEqual(expectedEmpty);
		expect(normalizeMediaValue({ source: 'empty', url: 'https://example.com/stale.png' })).toEqual(expectedEmpty);
		expect(normalizeMediaValue([])).toEqual(expectedEmpty);
	});

	test('normalizes legacy string URLs', () => {
		const normalized = normalizeMediaValue('  https://cdn.example.com/hero.webp  ');
		expect(normalized).toEqual({
			source: 'url',
			assetId: null,
			publicToken: null,
			url: 'https://cdn.example.com/hero.webp',
			previewUrl: null,
			thumbnailUrl: null,
			mimeType: null,
			width: null,
			height: null,
			focalPoint: { x: 0.5, y: 0.5 },
			dominantColor: null,
			defaultAlt: '',
		});
	});

	test('normalizes structured external URL objects with optional focalPoint', () => {
		const normalized = normalizeMediaValue({
			source: 'url',
			url: 'https://example.com/banner.jpg',
			focalPoint: { x: 0.25, y: 0.75 },
		});
		expect(normalized.source).toBe('url');
		expect(normalized.url).toBe('https://example.com/banner.jpg');
		expect(normalized.assetId).toBeNull();
		expect(normalized.focalPoint).toEqual({ x: 0.25, y: 0.75 });
	});

	test('normalizes structured DAM asset objects (camelCase and snake_case)', () => {
		const normalized = normalizeMediaValue({
			source: 'asset',
			assetId: 42,
			publicToken: 'tok_abc12345',
			url: '/assets/tok_abc12345/original',
			previewUrl: '/assets/tok_abc12345/medium',
			thumbnailUrl: '/assets/tok_abc12345/thumb',
			mimeType: 'image/webp',
			width: 1600,
			height: 900,
			focalPoint: { x: 0.2, y: 0.8 },
			dominantColor: '#3a4f66',
			defaultAlt: 'Mountain sunrise',
		});

		expect(normalized).toEqual({
			source: 'asset',
			assetId: 42,
			publicToken: 'tok_abc12345',
			url: '/assets/tok_abc12345/original',
			previewUrl: '/assets/tok_abc12345/medium',
			thumbnailUrl: '/assets/tok_abc12345/thumb',
			mimeType: 'image/webp',
			width: 1600,
			height: 900,
			focalPoint: { x: 0.2, y: 0.8 },
			dominantColor: '#3a4f66',
			defaultAlt: 'Mountain sunrise',
		});

		const snakeCase = normalizeMediaValue({
			asset_id: '77',
			public_token: 'tok_xyz98765',
			mime_type: 'image/jpeg',
			focal_point: { x: 2, y: -1 },
			dominant_color: '#112233',
			default_alt: 'Fallback alt',
		});
		expect(snakeCase.source).toBe('asset');
		expect(snakeCase.assetId).toBe(77);
		expect(snakeCase.publicToken).toBe('tok_xyz98765');
		expect(snakeCase.url).toBe('/assets/tok_xyz98765/original');
		expect(snakeCase.focalPoint).toEqual({ x: 1, y: 0 });
		expect(snakeCase.dominantColor).toBe('#112233');
		expect(snakeCase.defaultAlt).toBe('Fallback alt');
	});
});

describe('buildAssetMediaValue & buildUrlMediaValue', () => {
	test('builds structured asset media value from API DTO with variants', () => {
		const value = buildAssetMediaValue({
			id: 19,
			fileUrl: '/assets/token_998877/original',
			mimeType: 'image/jpeg',
			width: 2400,
			height: 1600,
			focalPoint: { x: 0.35, y: 0.45 },
			dominantColor: '#d9c6b0',
			defaultAlt: 'Studio portrait',
			variants: [
				{ key: 'thumb', url: '/assets/token_998877/thumb' },
				{ key: 'medium', url: '/assets/token_998877/medium' },
			],
		});

		expect(value).toEqual({
			source: 'asset',
			assetId: 19,
			publicToken: 'token_998877',
			url: '/assets/token_998877/original',
			previewUrl: '/assets/token_998877/medium',
			thumbnailUrl: '/assets/token_998877/thumb',
			mimeType: 'image/jpeg',
			width: 2400,
			height: 1600,
			focalPoint: { x: 0.35, y: 0.45 },
			dominantColor: '#d9c6b0',
			defaultAlt: 'Studio portrait',
		});
	});

	test('prefers explicit previewUrl and thumbnailUrl over variants', () => {
		const value = buildAssetMediaValue({
			id: 5,
			publicToken: 'abc123456',
			url: '/assets/abc123456/original',
			previewUrl: '/assets/abc123456/preview',
			thumbnailUrl: '/assets/abc123456/small',
			variants: [{ key: 'medium', url: '/assets/abc123456/medium' }],
		});

		expect(value.previewUrl).toBe('/assets/abc123456/preview');
		expect(value.thumbnailUrl).toBe('/assets/abc123456/small');
	});

	test('buildUrlMediaValue trims input and handles non-strings', () => {
		expect(buildUrlMediaValue('  https://example.com/a.png  ')).toEqual({
			source: 'url',
			url: 'https://example.com/a.png',
		});
		expect(buildUrlMediaValue(null)).toEqual({
			source: 'url',
			url: '',
		});
	});
});

describe('resolvePreviewUrl', () => {
	test('prefers previewUrl -> thumbnailUrl -> url -> publicToken fallback', () => {
		expect(resolvePreviewUrl({
			previewUrl: '/p.webp',
			thumbnailUrl: '/t.webp',
			url: '/o.webp',
		})).toBe('/p.webp');

		expect(resolvePreviewUrl({
			previewUrl: '',
			thumbnailUrl: '/t.webp',
			url: '/o.webp',
		})).toBe('/t.webp');

		expect(resolvePreviewUrl({
			previewUrl: null,
			thumbnailUrl: null,
			url: '/o.webp',
		})).toBe('/o.webp');

		expect(resolvePreviewUrl({
			publicToken: 'tok_only_123',
		})).toBe('/assets/tok_only_123/original');

		expect(resolvePreviewUrl('  https://example.com/img.png ')).toBe('https://example.com/img.png');
		expect(resolvePreviewUrl(null)).toBe('');
		expect(resolvePreviewUrl({})).toBe('');
	});
});

describe('formatObjectPosition', () => {
	test('formats defaults, clamps out-of-range values, and rounds to 1 decimal', () => {
		expect(formatObjectPosition(null)).toBe('50% 50%');
		expect(formatObjectPosition(undefined)).toBe('50% 50%');
		expect(formatObjectPosition({ x: 0.5, y: 0.5 })).toBe('50% 50%');
		expect(formatObjectPosition({ x: -0.4, y: 1.8 })).toBe('0% 100%');
		expect(formatObjectPosition({ x: 1 / 3, y: 2 / 3 })).toBe('33.3% 66.7%');
		expect(formatObjectPosition({ x: 0.125, y: 0.875 })).toBe('12.5% 87.5%');
		expect(formatObjectPosition({ x: 'invalid', y: Number.NaN })).toBe('50% 50%');
	});
});

describe('formatBytes', () => {
	test('formats zero, bytes, KB, MB, GB, and invalid inputs', () => {
		expect(formatBytes(0)).toBe('0 B');
		expect(formatBytes(-50)).toBe('0 B');
		expect(formatBytes(null)).toBe('0 B');
		expect(formatBytes(Number.NaN)).toBe('0 B');
		expect(formatBytes(512)).toBe('512 B');
		expect(formatBytes(1024)).toBe('1 KB');
		expect(formatBytes(1536)).toBe('1.5 KB');
		expect(formatBytes(1048576)).toBe('1 MB');
		expect(formatBytes(2.5 * 1024 ** 3)).toBe('2.5 GB');
	});
});

describe('mimeMatchesAccept', () => {
	test('matches wildcard, category wildcards, exact lists, parameters, and extensions', () => {
		expect(mimeMatchesAccept('image/jpeg', '*/*')).toBe(true);
		expect(mimeMatchesAccept('application/pdf', '')).toBe(true);
		expect(mimeMatchesAccept('image/webp', 'image/*')).toBe(true);
		expect(mimeMatchesAccept('video/mp4', 'image/*')).toBe(false);
		expect(mimeMatchesAccept('video/webm', 'video/*')).toBe(true);
		expect(mimeMatchesAccept('image/png', 'image/jpeg,image/png')).toBe(true);
		expect(mimeMatchesAccept('image/gif', 'image/jpeg, image/png')).toBe(false);
		expect(mimeMatchesAccept('image/svg+xml; charset=utf-8', 'image/svg+xml')).toBe(true);
		expect(mimeMatchesAccept('image/png', '.png,.jpg')).toBe(true);
		expect(mimeMatchesAccept('', 'image/*')).toBe(false);
		expect(mimeMatchesAccept(null, 'image/*')).toBe(false);
		expect(isImageMimeType('image/avif')).toBe(true);
		expect(isImageMimeType('application/pdf')).toBe(false);
	});
});

describe('URL & API response helpers', () => {
	test('isValidHttpsUrl accepts only valid https URLs', () => {
		expect(isValidHttpsUrl('https://example.com/a.webp')).toBe(true);
		expect(isValidHttpsUrl('http://example.com/a.webp')).toBe(false);
		expect(isValidHttpsUrl('javascript:alert(1)')).toBe(false);
		expect(isValidHttpsUrl('/local/path.jpg')).toBe(false);
		expect(isValidHttpsUrl('')).toBe(false);
	});

	test('resolveAssetEndpoint avoids duplicating /api prefix', () => {
		expect(resolveAssetEndpoint('/api', '/api/editor/assets')).toBe('/api/editor/assets');
		expect(resolveAssetEndpoint('/api', '/api/editor/assets', '/upload')).toBe('/api/editor/assets/upload');
		expect(resolveAssetEndpoint('https://cms.example.com/api', '/api/admin/assets')).toBe('https://cms.example.com/api/admin/assets');
		expect(resolveAssetEndpoint('', '/api/editor/assets')).toBe('/api/editor/assets');
		expect(resolveAssetEndpoint('/sub/api', '/editor/assets')).toBe('/sub/api/editor/assets');
	});

	test('parseAssetListResponse normalizes admin, editor, and Hydra response shapes', () => {
		const res = parseAssetListResponse({
			assets: [{ id: 1, title: 'Hero' }],
			totalItems: 30,
			page: 2,
			itemsPerPage: 24,
			folders: [{ id: 3, name: 'Marketing' }],
			tags: ['hero', { id: 9, name: 'Homepage', slug: 'homepage' }],
		});
		expect(res.assets).toHaveLength(1);
		expect(res.totalItems).toBe(30);
		expect(res.page).toBe(2);
		expect(res.folders).toEqual([{ id: 3, name: 'Marketing' }]);
		expect(res.tags).toEqual([
			{ name: 'hero', slug: 'hero' },
			{ id: 9, name: 'Homepage', slug: 'homepage' },
		]);

		const hydra = parseAssetListResponse({
			'hydra:member': [{ id: 10 }, { id: 11 }],
			'hydra:totalItems': 2,
		});
		expect(hydra.assets).toHaveLength(2);
		expect(hydra.totalItems).toBe(2);
		expect(hydra.folders).toEqual([]);
		expect(hydra.tags).toEqual([]);
	});
});
