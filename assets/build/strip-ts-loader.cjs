'use strict';

const { stripTypeScriptTypes } = require('node:module');

/**
 * Transpile TypeScript syntax to JavaScript for Webpack without depending on
 * ts-loader's legacy TypeScript JS compiler API (removed in TypeScript 7).
 */
module.exports = function stripTsLoader(source) {
	if (typeof Bun !== 'undefined' && typeof Bun.Transpiler === 'function') {
		const transpiler = new Bun.Transpiler({ loader: 'ts' });
		return transpiler.transformSync(source);
	}

	return stripTypeScriptTypes(source, {
		mode: 'transform',
		sourceUrl: this.resourcePath,
	});
};
