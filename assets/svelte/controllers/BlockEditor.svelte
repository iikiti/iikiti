<script>
	import { onMount } from 'svelte';
	import { get_current_component } from 'svelte/internal';

	let node = get_current_component().$$.root;

	let template = node.previousSibling.matches("template[data-for='BlockEditor']") ?
		get_current_component().$$.root.previousSibling :
		null;
	
	if(!template) {
		throw new Error("Invalid editor template");
	}

	let wrapper = template.content.firstElementChild.cloneNode(true);


	onMount(function() {
		//console.log("mounted", node);
	});
</script>

<div>Test 2</div>