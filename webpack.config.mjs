import Encore from '@symfony/webpack-encore';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// Manually configure the runtime environment if not already configured yet by the "encore" command.
// It's useful when you use tools that rely on webpack.config.js file.
if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    // directory where compiled assets will be stored
    .setOutputPath('public/build/')
    // public path used by the web server to access the output path
    .setPublicPath('/build')
    // only needed for CDNs or sub-directory deploy
    //.setManifestKeyPrefix('build/')

    .copyFiles([
        {
            from: './assets/images',
            to: 'images/[path][name].[ext]',
        }
    ])

    /*
     * ENTRY CONFIG
     *
     * Each entry will result in one JavaScript file (e.g. app.js)
     * and one CSS file (e.g. app.css) if your JavaScript imports CSS.
     */
    .addStyleEntry('tailwind', './assets/styles/app.css')

	.addEntry('app', './assets/app.js')
    .addEntry('admin', './assets/admin.js')

    // When enabled, Webpack "splits" your files into smaller pieces for greater optimization.
    .splitEntryChunks()

    //.enableVueLoader(() => {}, { runtimeCompilerBuild: false })

    // will require an extra script tag for runtime.js
    // but, you probably want this, unless you're building a single-page app
    .enableSingleRuntimeChunk()

    /*
     * FEATURE CONFIG
     *
     * Enable & configure other features below. For a full
     * list of features, see:
     * https://symfony.com/doc/current/frontend.html#adding-more-features
     */
    .cleanupOutputBeforeBuild()
    .enableBuildNotifications()
    .enableSourceMaps(!Encore.isProduction())
    // enables hashed filenames (e.g. app.abc123.css)
    .enableVersioning(Encore.isProduction())

	/*
    .configureBabel((config) => {
        config.plugins.push('@babel/plugin-proposal-class-properties');
    })

    // enables @babel/preset-env polyfills
    .configureBabelPresetEnv((config) => {
        config.useBuiltIns = 'usage';
        config.corejs = 3;
    })
	*/

    // enables Sass/SCSS support
    //.enableSassLoader((options) => {
	//	options.api = "modern-compiler";
	//	return options;
	//})

    // Enable PostCSS loader
    .enablePostCssLoader()

    // uncomment if you use TypeScript
    //.enableTypeScriptLoader()

    // uncomment if you use React
    //.enableReactPreset()

    // uncomment to get integrity="..." attributes on your script & link tags
    // requires WebpackEncoreBundle 1.4 or higher
    //.enableIntegrityHashes(Encore.isProduction())

    // uncomment if you're having problems with a jQuery plugin
    //.autoProvidejQuery()
;

const webpackConfig = await Encore.getWebpackConfig();

webpackConfig.resolve = webpackConfig.resolve || {};
webpackConfig.resolve.alias = {
	...webpackConfig.resolve.alias,
	'$lib': path.resolve(__dirname, 'assets/svelte/admin/lib'),
	'$types': path.resolve(__dirname, 'assets/svelte/admin/types/index.ts'),
	'$components': path.resolve(__dirname, 'assets/svelte/admin/components'),
	'$routes': path.resolve(__dirname, 'assets/svelte/admin/routes'),
};

webpackConfig.resolve.conditionNames = (webpackConfig.resolve.conditionNames??[]);
if(webpackConfig.resolve.conditionNames.indexOf('svelte') < 0) {
	webpackConfig.resolve.conditionNames.push('svelte');
}

webpackConfig.resolve.conditionNames.push('svelte', 'browser');

webpackConfig.module.rules.push(
	{
        test: /\.svelte\.ts$/,
        use: [ "svelte-loader", { loader: "ts-loader", options: { transpileOnly: true } }],
    },
    // This is the config for other .ts files - the regex makes sure to not process .svelte.ts files twice
    {
        test: /(?<!\.svelte)\.ts$/,
        loader: "ts-loader",
        options: {
          transpileOnly: true, // you should use svelte-check for type checking
        }
    },
    {
    	test: /\.(svelte|svelte\.js)$/,
        use: 'svelte-loader'
    },
);

//console.log(webpackConfig.module.rules);return;

export default webpackConfig;