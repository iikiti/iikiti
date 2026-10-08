<script lang="ts">
	interface Props {
		label?: string;
	}

	let { label = 'Loading…' }: Props = $props();
</script>

	<div class="text-center py-8">
		<div class="animate-spin rounded-full h-8 w-8 border-2 border-transparent border-t-accent mx-auto mb-3"></div>
		<p class="text-text-muted">{label}</p>
	</div>
