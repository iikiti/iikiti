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

for(let vc of document.querySelectorAll("[data-vue-component]")) {
	let config = JSON.parse(vc.dataset.vueComponent);
	import("./vue/controllers/" + config.name + ".vue").then(((config, vc, component) => {
		let app = createApp(component.default);
		app.mount(vc);
	}).bind(null, config, vc));
}