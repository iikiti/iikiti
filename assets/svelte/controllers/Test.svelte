<script>
	import { onMount } from 'svelte';
	
	console.log('Test');

	onMount(function() {
		console.log("Test mounted", node);
	});
</script>

<div>Test 1</div>