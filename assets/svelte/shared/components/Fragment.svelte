<script lang="ts">
	interface FragmentResult {
		html: string;
	}

	interface FragmentApi {
		load: (provider: string, options: { params: Record<string, unknown>; signal: AbortSignal }) => Promise<FragmentResult>;
	}

	interface Props {
		provider: string;
		params?: Record<string, unknown>;
		loadingLabel?: string;
		errorMessage?: string;
	}

	let {
		provider,
		params = {},
		loadingLabel = 'Loading fragment...',
		errorMessage = 'The fragment could not be loaded.',
	}: Props = $props();

	let html = $state('');
	let loading = $state(true);
	let error = $state('');
	let requestVersion = 0;

	$effect(() => {
		const currentProvider = provider;
		const paramsJson = JSON.stringify(params ?? {});
		const currentParams = JSON.parse(paramsJson) as Record<string, unknown>;
		const version = ++requestVersion;
		const controller = new AbortController();
		loading = true;
		error = '';

		const fragments = (window as Window & { iikiti?: { fragments?: FragmentApi } }).iikiti?.fragments;
		if (!fragments) {
			error = errorMessage;
			loading = false;
			return () => controller.abort();
		}

		fragments.load(currentProvider, { params: currentParams, signal: controller.signal })
			.then((result) => {
				if (version !== requestVersion) return;
				html = result.html;
				loading = false;
			})
			.catch(() => {
				if (version !== requestVersion || controller.signal.aborted) return;
				error = errorMessage;
				loading = false;
			});

		return () => controller.abort();
	});
</script>

<div class="iikiti-fragment" aria-busy={loading}>
	{#if loading}
		<p role="status">{loadingLabel}</p>
	{:else if error}
		<p role="alert">{error}</p>
	{:else}
		{@html html}
	{/if}
</div>
