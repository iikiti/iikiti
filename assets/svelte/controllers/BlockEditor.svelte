<script>
	import { onMount } from 'svelte';
	import { get_current_component } from 'svelte/internal';

	var node = get_current_component().$$.root;

	/*
	console.log(node.previousSibling?.matches('test'));
	/*
	console.log(node.previousSibling?.matches('test'));
	let template = node.previousSibling.matches("template[data-for='BlockEditor']") ?
		get_current_component().$$.root.previousSibling :
		null;
	
	if(!template) {
		throw new Error("Invalid editor template");
	}

	*/
	console.log('BlockEditor');


	onMount(function() {
		console.log("BlockEditor Mounted", node);
	});
</script>

<div>Test 2</div>