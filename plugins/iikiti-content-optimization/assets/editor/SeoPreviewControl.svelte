<script>
	let { field } = $props();
	const preview = $derived(field.preview ?? {});
	const title = $derived(preview.title || 'Page title');
	const description = $derived(preview.description || 'Add a search description to preview it here.');
	const openGraphTitle = $derived(preview.openGraphTitle || preview.title || 'Page title');
	const openGraphDescription = $derived(preview.openGraphDescription || preview.description || 'Add a social description to preview it here.');
	const twitterTitle = $derived(preview.twitterTitle || preview.openGraphTitle || preview.title || 'Page title');
	const twitterDescription = $derived(preview.twitterDescription || preview.openGraphDescription || preview.description || 'Add a card description to preview it here.');
	const pageAddress = typeof window === 'undefined'
		? 'https://example.com'
		: `${window.location.origin}${window.location.pathname}`;
	const address = $derived.by(() => {
		const canonical = typeof preview.canonicalUrl === 'string' ? preview.canonicalUrl.trim() : '';
		if (!canonical) return pageAddress;
		if (canonical.startsWith('/') && !canonical.startsWith('//')) {
			const origin = typeof window === 'undefined' ? 'https://example.com' : window.location.origin;
			return `${origin}${canonical}`;
		}
		return canonical;
	});
	const hasOpenGraphImage = $derived(Boolean(preview.openGraphImage));
</script>

<div class="preview-cards" role="region" aria-labelledby={`${String(field.key)}-label`}>
	<article class="preview-card preview-card--search">
		<p class="preview-eyebrow">Search result</p>
		<p class="preview-address">{address}</p>
		<p class="preview-title">{title}</p>
		<p class="preview-description">{description}</p>
	</article>

	<article class="preview-card preview-card--social">
		<div class="preview-image">
			{hasOpenGraphImage ? 'Image URL configured' : 'No image selected'}
		</div>
		<div class="preview-social-copy">
			<p class="preview-eyebrow">Open Graph | {preview.openGraphType || 'website'}</p>
			<p class="preview-title">{openGraphTitle}</p>
			<p class="preview-description">{openGraphDescription}</p>
		</div>
	</article>

	<article class="preview-card preview-card--twitter">
		<div class="preview-image">
			{preview.twitterImage ? 'Image URL configured' : 'No image selected'}
		</div>
		<div class="preview-social-copy">
			<p class="preview-eyebrow">X / Twitter | {preview.twitterCard || 'summary_large_image'}</p>
			<p class="preview-title">{twitterTitle}</p>
			<p class="preview-description">{twitterDescription}</p>
		</div>
	</article>

	<p class="preview-note">Preview text updates as you edit. Remote images are not loaded.</p>
</div>

<style>
	.preview-cards,
	.preview-card,
	.preview-social-copy {
		min-width: 0;
		box-sizing: border-box;
	}

	.preview-cards {
		display: grid;
		gap: 8px;
		width: 100%;
		font: 1rem/1.4 system-ui, sans-serif;
	}

	.preview-card {
		margin: 0;
		padding: 9px;
		border: 1px solid var(--ik-panel-border, #dadce0);
		border-radius: 8px;
		background: var(--ik-panel-bg, #fff);
		color: var(--ik-panel-text, #202124);
		overflow-wrap: anywhere;
	}

	.preview-card p {
		margin: 0;
	}

	.preview-eyebrow {
		margin-bottom: 4px !important;
		color: var(--ik-panel-text-muted, #5f6368);
		font-size: 1rem;
		text-transform: uppercase;
		letter-spacing: 0.04em;
	}

	.preview-address {
		color: var(--ik-panel-text-muted, #3c4043);
		font-size: 1rem;
	}

	.preview-title {
		margin-top: 3px !important;
		color: var(--ik-accent, #1a0dab);
		font-size: 1.0625rem;
		line-height: 1.3;
	}

	.preview-description {
		display: -webkit-box;
		margin-top: 3px !important;
		color: var(--ik-panel-text-muted, #4d5156);
		overflow: hidden;
		-webkit-box-orient: vertical;
		-webkit-line-clamp: 3;
	}

	.preview-image {
		display: grid;
		min-height: 42px;
		margin: -9px -9px 8px;
		padding: 6px;
		place-items: center;
		background: color-mix(in srgb, var(--ik-panel-text, #202124) 6%, var(--ik-panel-bg, #fff));
		color: var(--ik-panel-text-muted, #5f6368);
		font-size: 1rem;
	}

	.preview-note {
		color: var(--ik-panel-text-muted, #5f6368);
		font-size: 1rem;
	}
</style>
