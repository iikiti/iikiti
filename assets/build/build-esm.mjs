import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import * as acorn from 'acorn';
import webpack from 'webpack';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const rootDir = path.resolve(__dirname, '../..');
const isProd = process.argv.includes('--prod') || process.env.NODE_ENV === 'production';
const mode = isProd ? 'production' : 'development';
const esmOutputDir = path.join(rootDir, 'public/build/esm');
const stripTsLoader = path.join(rootDir, 'assets/build/strip-ts-loader.cjs');
const sveltePkgDir = path.join(rootDir, 'node_modules/svelte/src');
const svelteRootDir = path.join(rootDir, 'node_modules/svelte');

const EXCLUDED_SVELTE_EXPORTS = new Set([
	'./package.json',
	'./compiler',
	'./server',
	'./internal',
	'./internal/server',
]);

function resolveSvelteBrowserSpecifiers(pkgRootDir) {
	const pkgJsonPath = path.join(pkgRootDir, 'package.json');
	const pkg = JSON.parse(fs.readFileSync(pkgJsonPath, 'utf8'));
	const exportsMap = pkg.exports ?? {};
	const specifiers = {};

	for (const [subpath, target] of Object.entries(exportsMap)) {
		if (EXCLUDED_SVELTE_EXPORTS.has(subpath)) {
			continue;
		}
		const resolved = typeof target === 'string'
			? target
			: (target?.browser ?? target?.default ?? null);
		if (typeof resolved !== 'string' || !resolved.endsWith('.js')) {
			continue;
		}
		const specifier = subpath === '.' ? 'svelte' : `svelte/${subpath.slice(2)}`;
		specifiers[specifier] = path.join(pkgRootDir, resolved);
	}

	if (Object.keys(specifiers).length === 0) {
		throw new Error(`No browser Svelte specifiers resolved from ${pkgJsonPath}.`);
	}

	return specifiers;
}

const SVELTE_SPECIFIERS = resolveSvelteBrowserSpecifiers(svelteRootDir);

function isBareModuleSpecifier(specifier) {
	return !specifier.startsWith('.')
		&& !specifier.startsWith('/')
		&& !specifier.startsWith('http:')
		&& !specifier.startsWith('https:');
}

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
			&& isBareModuleSpecifier(node.source.value)
		) {
			bare.add(node.source.value);
		}
	}
	return bare;
}

function assertImportMapCoversBundles(manifestImports, bundleFiles) {
	const missing = [];
	for (const filePath of bundleFiles) {
		const source = fs.readFileSync(filePath, 'utf8');
		for (const specifier of extractBareSpecifiers(source)) {
			if (!Object.prototype.hasOwnProperty.call(manifestImports, specifier)) {
				missing.push(`${specifier} (referenced in ${path.relative(rootDir, filePath)})`);
			}
		}
	}
	if (missing.length > 0) {
		throw new Error(`Unmapped bare module specifier(s) in built ESM bundles:\n- ${missing.join('\n- ')}`);
	}
}

function flatEntryName(specifier) {
	return specifier.replace(/\//g, '-');
}

function runWebpack(config) {
	return new Promise((resolve, reject) => {
		webpack(config, (err, stats) => {
			if (err) {
				reject(err);
				return;
			}
			const info = stats.toJson({ all: false, errors: true, warnings: true, assets: true, assetsByChunkName: true });
			if (stats.hasErrors()) {
				const details = info.errors.map((e) => e.message).join('\n\n');
				reject(new Error(`Webpack ESM build failed:\n${details}`));
				return;
			}
			resolve(info);
		});
	});
}

function commonModuleRules() {
	return [
		{
			test: /\.svelte\.ts$/,
			use: [
				{ loader: 'svelte-loader', options: { emitCss: false } },
				stripTsLoader,
			],
		},
		{
			test: /(?<!\.svelte)\.ts$/,
			use: stripTsLoader,
		},
		{
			test: /\.(svelte|svelte\.js)$/,
			use: [{ loader: 'svelte-loader', options: { emitCss: false } }],
		},
	];
}

function commonAliases() {
	return {
		'$lib': path.join(rootDir, 'assets/svelte/shared/lib'),
		'$types': path.join(rootDir, 'assets/svelte/shared/types/index.ts'),
		'$components': path.join(rootDir, 'assets/svelte/shared/components'),
		'$routes': path.join(rootDir, 'assets/svelte/admin/routes'),
		'$editor': path.join(rootDir, 'assets/svelte/editor'),
		'$framework': path.join(rootDir, 'assets/js/iikiti'),
		'$iikiti': path.join(rootDir, 'assets/js/iikiti'),
	};
}

function svelteExternals({ includeUi = true } = {}) {
	return ({ request }, callback) => {
		if (!request) {
			callback();
			return;
		}
		if (request === 'svelte' || request.startsWith('svelte/')) {
			callback(null, `module ${request}`);
			return;
		}
		if (includeUi && (request === '@iikiti/ui' || request === '@iikiti/admin')) {
			callback(null, 'module @iikiti/ui');
			return;
		}
		callback();
	};
}

function firstJsAsset(assetsByChunkName, chunkName) {
	const list = assetsByChunkName[chunkName];
	if (!Array.isArray(list)) {
		return null;
	}
	return list.find((file) => file.endsWith('.js') || file.endsWith('.mjs')) ?? null;
}

async function build() {
	fs.rmSync(esmOutputDir, { recursive: true, force: true });
	fs.mkdirSync(esmOutputDir, { recursive: true });

	const svelteEntries = {};
	for (const [specifier, target] of Object.entries(SVELTE_SPECIFIERS)) {
		svelteEntries[flatEntryName(specifier)] = target;
	}

	// Pass 1: Shared Svelte 5 runtime subpaths in a single splitChunks compilation
	// so every subpath shares one physical runtime chunk instance.
	const runtimeStats = await runWebpack({
		name: 'svelte-runtime',
		mode,
		devtool: isProd ? false : 'source-map',
		target: ['web', 'es2022'],
		entry: svelteEntries,
		experiments: { outputModule: true },
		output: {
			path: esmOutputDir,
			publicPath: '/build/esm/',
			filename: isProd ? '[name].[contenthash:8].mjs' : '[name].mjs',
			chunkFilename: isProd ? '[name].[contenthash:8].mjs' : '[name].mjs',
			module: true,
			library: { type: 'module' },
			chunkFormat: 'module',
			chunkLoading: 'import',
		},
		resolve: {
			conditionNames: ['import', 'browser', 'svelte', 'default'],
		},
		optimization: {
			runtimeChunk: 'single',
			concatenateModules: false,
			splitChunks: {
				chunks: 'all',
				minSize: 0,
				cacheGroups: {
					svelteShared: {
						test: /[\\/]node_modules[\\/]/,
						name: 'svelte-shared',
						chunks: 'all',
						enforce: true,
					},
				},
			},
		},
	});

	// Pass 2: Shared @iikiti/ui + host ESM entries (admin, editor) externalizing svelte/*
	const hostStats = await runWebpack({
		name: 'host-esm',
		mode,
		devtool: isProd ? false : 'source-map',
		target: ['web', 'es2022'],
		entry: {
			'iikiti-ui': path.join(rootDir, 'assets/svelte/shared/components/index.ts'),
			'admin': path.join(rootDir, 'assets/admin.js'),
			'editor': path.join(rootDir, 'assets/svelte/editor/mount.js'),
		},
		experiments: { outputModule: true },
		output: {
			path: esmOutputDir,
			publicPath: '/build/esm/',
			filename: isProd ? '[name].[contenthash:8].mjs' : '[name].mjs',
			chunkFilename: isProd ? '[name].[contenthash:8].mjs' : '[name].mjs',
			module: true,
			library: { type: 'module' },
			chunkFormat: 'module',
			chunkLoading: 'import',
		},
		externalsType: 'module',
		externals: svelteExternals({ includeUi: false }),
		resolve: {
			alias: {
				...commonAliases(),
				'@iikiti/ui': path.join(rootDir, 'assets/svelte/shared/components/index.ts'),
				'@iikiti/admin': path.join(rootDir, 'assets/svelte/shared/components/index.ts'),
			},
			conditionNames: ['import', 'svelte', 'browser', 'default'],
			extensions: ['.ts', '.js', '.mjs', '.svelte'],
		},
		module: {
			rules: commonModuleRules(),
		},
	});

	// Build import map manifest consumed by ImportMapTwigExtension
	const imports = {};
	for (const specifier of Object.keys(SVELTE_SPECIFIERS)) {
		const asset = firstJsAsset(runtimeStats.assetsByChunkName, flatEntryName(specifier));
		if (!asset) {
			throw new Error(`Missing built asset for ${specifier}`);
		}
		imports[specifier] = `/build/esm/${asset}`;
	}
	const uiAsset = firstJsAsset(hostStats.assetsByChunkName, 'iikiti-ui');
	const adminAsset = firstJsAsset(hostStats.assetsByChunkName, 'admin');
	const editorAsset = firstJsAsset(hostStats.assetsByChunkName, 'editor');
	if (!uiAsset || !adminAsset || !editorAsset) {
		throw new Error('Missing host ESM output asset(s)');
	}
	imports['@iikiti/ui'] = `/build/esm/${uiAsset}`;
	imports['@iikiti/admin'] = `/build/esm/${uiAsset}`;
	imports['@iikiti/editor'] = `/build/esm/${editorAsset}`;

	const manifest = {
		imports,
		entries: {
			admin: `/build/esm/${adminAsset}`,
			editor: `/build/esm/${editorAsset}`,
			ui: `/build/esm/${uiAsset}`,
		},
	};
	fs.writeFileSync(path.join(esmOutputDir, 'importmap.json'), JSON.stringify(manifest, null, 2) + '\n');

	// Pass 3: Monorepo plugin ESM bundles (externalizing svelte/* and @iikiti/ui)
	const pluginEntriesPath = path.join(rootDir, 'assets/build/esm-entries.json');
	const pluginEntries = JSON.parse(fs.readFileSync(pluginEntriesPath, 'utf8'));
	const builtBundles = [
		path.join(esmOutputDir, adminAsset),
		path.join(esmOutputDir, editorAsset),
		path.join(esmOutputDir, uiAsset),
	];
	let bundledCount = 0;
	for (const spec of pluginEntries) {
		if (spec.entry && spec.outputPath && spec.filename) {
			const outDir = path.join(rootDir, spec.outputPath);
			fs.mkdirSync(outDir, { recursive: true });
			await runWebpack({
				name: `plugin:${spec.entry}`,
				mode,
				devtool: isProd ? false : 'source-map',
				target: ['web', 'es2022'],
				entry: path.resolve(rootDir, spec.entry),
				experiments: { outputModule: true },
				output: {
					path: outDir,
					filename: spec.filename,
					module: true,
					library: { type: 'module' },
					chunkFormat: 'module',
					chunkLoading: 'import',
				},
				externalsType: 'module',
				externals: svelteExternals({ includeUi: true }),
				resolve: {
					alias: commonAliases(),
					conditionNames: ['import', 'svelte', 'browser', 'default'],
					extensions: ['.ts', '.js', '.mjs', '.svelte'],
				},
				module: {
					rules: commonModuleRules(),
				},
			});
			builtBundles.push(path.join(outDir, spec.filename));
			bundledCount++;
		}

		if (Array.isArray(spec.copy)) {
			for (const copySpec of spec.copy) {
				const srcDir = path.resolve(rootDir, copySpec.from);
				const destDir = path.resolve(rootDir, copySpec.to);
				if (!fs.existsSync(srcDir)) continue;
				const matcher = copySpec.pattern ? new RegExp(copySpec.pattern) : null;
				fs.mkdirSync(destDir, { recursive: true });
				for (const file of fs.readdirSync(srcDir)) {
					if (!matcher || matcher.test(file)) {
						fs.copyFileSync(path.join(srcDir, file), path.join(destDir, file));
					}
				}
			}
		}
	}

	assertImportMapCoversBundles(imports, builtBundles);

	console.log(`Built ${Object.keys(imports).length} importmap entries and ${bundledCount} plugin ESM bundle(s) in ${mode} mode.`);
}

build().catch((err) => {
	console.error(err);
	process.exit(1);
});
