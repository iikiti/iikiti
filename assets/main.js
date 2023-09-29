'use strict';

import '/assets/js/utilities/XPathResult.js';

// Get all custom elements
let ce = Array.from(document.querySelectorAll("[data-svelte-component]"));
var ceTagObj = {};

ce.forEach((elem) => {
	let tag = "svelteComponent" in elem.dataset && elem.dataset.svelteComponent !== "" ?
		elem.dataset.svelteComponent :
		elem.tagName.split(':', 2)[1].toLowerCase();
	if(!(tag in ceTagObj)) {
		ceTagObj[tag] = import(
			/* webpackChunkName: "js/chunks/block-editor-web-components" */
			/* webpackMode: "lazy" */
			/* webpackPrefetch: true */
			/* webpackPreload: true */
			'/assets/js/components/' + tag + '.svelte'
		);
	}
	ceTagObj[tag].then(((elem, tag, module) => {
		new module.default({
			target: elem
		});
	}).bind(null, elem, tag));
});
