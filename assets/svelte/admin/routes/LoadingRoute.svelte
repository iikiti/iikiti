<script lang="ts">
	import LoadingState from '../components/LoadingState.svelte';

	interface Props {
		label?: string;
	}

	let { label = 'Loading…' }: Props = $props();
</script>

<LoadingState {label} />
