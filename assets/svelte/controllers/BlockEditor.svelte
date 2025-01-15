<script lang="ts">
	import { onMount } from 'svelte';
	let { children } = $props();

	console.log('BlockEditor', children);

	onMount(function() {
		console.log("BlockEditor Mounted");
	});
</script>

<div>{@render children?.()}</div>