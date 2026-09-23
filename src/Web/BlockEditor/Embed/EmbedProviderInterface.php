<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Embed;

/**
 * A single provider capable of turning a URL into embed HTML.
 *
 * Core providers: YouTube, Vimeo, generic video, and oEmbed-backed social providers.
 * Plugins register additional providers by implementing this interface and the
 * `iikiti.cms.embed_provider` tag (auto-tagged via {@see EmbedProviderInterface}).
 */
interface EmbedProviderInterface
{
	/**
	 * Whether this provider can handle the given URL.
	 */
	public function supports(string $url): bool;

	/**
	 * Returns the rendered HTML for the URL.
	 *
	 * @param array<string,mixed> $options Block-level style/content options (e.g. aspect ratio)
	 */
	public function render(string $url, array $options = []): string;
}
