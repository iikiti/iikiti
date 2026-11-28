import { describe, expect, test } from 'bun:test';
import fs from 'node:fs';
import path from 'node:path';
import * as acorn from 'acorn';

const rootDir = path.resolve(import.meta.dir, '../..');
const publicDir = path.join(rootDir, 'public');
const importMapPath = path.join(publicDir, 'build/esm/importmap.json');
const pluginEntriesPath = path.join(rootDir, 'assets/build/esm-entries.json');

function extractBareSpecifiers(source) {
	const bare = new Set();
	const ast = acorn.parse(source, { ecmaVersion: 'latest', sourceType: 'module' });
	for (const node of ast.body) {
		if (
			(node.type === 'ImportDeclaration'
				|| node.type === 'ExportNamedDeclaration'
				|| node.type === 'ExportAllDeclaration')
			&& node.source
			&& typeof node.source.value === 'string'
		) {
			const specifier = node.source.value;
			if (
				!specifier.startsWith('.')
				&& !specifier.startsWith('/')
				&& !specifier.startsWith('http:')
				&& !specifier.startsWith('https:')
			) {
				bare.add(specifier);
			}
		}
	}
	return bare;
}

describe('ESM import map completeness', () => {
	test('maps svelte/internal/init-operations and resolves all mapped files on disk', () => {
		const manifest = JSON.parse(fs.readFileSync(importMapPath, 'utf8'));

		expect(typeof manifest.imports['svelte/internal/init-operations']).toBe('string');
		expect(manifest.imports['svelte/internal/init-operations']).toMatch(/^\/build\/esm\/.*\.mjs$/);

		for (const urlPath of [...Object.values(manifest.imports), ...Object.values(manifest.entries)]) {
			expect(urlPath.startsWith('/')).toBe(true);
			const filePath = path.join(publicDir, urlPath.slice(1));
			expect(fs.existsSync(filePath)).toBe(true);
		}
	});

	test('covers every bare specifier imported by host and plugin ESM bundles', () => {
		const manifest = JSON.parse(fs.readFileSync(importMapPath, 'utf8'));
		const pluginEntries = JSON.parse(fs.readFileSync(pluginEntriesPath, 'utf8'));

		const bundleFiles = Object.values(manifest.entries).map((urlPath) =>
			path.join(publicDir, urlPath.slice(1)),
		);
		for (const spec of pluginEntries) {
			if (spec.entry && spec.outputPath && spec.filename) {
				bundleFiles.push(path.join(rootDir, spec.outputPath, spec.filename));
			}
		}

		const unmapped = [];
		for (const filePath of bundleFiles) {
			const source = fs.readFileSync(filePath, 'utf8');
			for (const specifier of extractBareSpecifiers(source)) {
				if (!Object.prototype.hasOwnProperty.call(manifest.imports, specifier)) {
					unmapped.push(`${specifier} in ${path.relative(rootDir, filePath)}`);
				}
			}
		}

		expect(unmapped).toEqual([]);
	});
});
