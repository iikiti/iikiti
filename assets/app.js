import './js/utilities/Element.furthest';

const { createApp } = await import('vue');
/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import './styles/app.css';

// start the Stimulus application
import './bootstrap';

var compSelector = "[data-vue-component]";

for(let vc of document.querySelectorAll(compSelector)) {
	let config = JSON.parse(vc.dataset.vueComponent);
	import(
		/* webpackInclude: /\.vue$/ */
		/* webpackChunkName: "vue-component-[request]" */
		/* webpackMode: "lazy" */
		/* webpackPrefetch: true */
		/* webpackPreload: true */
		`./vue/controllers/${config.name}.vue`
	).then(((config, vc, component) => {
		const app = createApp(component.default);
		app.component(component.default.__name, component.default);
		app.mount(vc);
	}).bind(null, config, vc));
}