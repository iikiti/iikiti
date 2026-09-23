<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Embed\Provider;

use iikiti\CMS\Web\BlockEditor\Embed\EmbedProviderInterface;

/**
 * Vimeo URLs -> embed iframe.
 */
final class VimeoProvider implements EmbedProviderInterface
{
	public function supports(string $url): bool
	{
		return (bool) preg_match('#^https?://(?:www\.)?vimeo\.com/(?:[0-9a-f]+/)?(\d+)(?:/|\?|$)#i', $url);
	}

	public function render(string $url, array $options = []): string
	{
		$id = $this->extractId($url);
		if (null === $id) {
			return '';
		}
		$ratio = (string) ($options['aspectRatio'] ?? '16:9');

		return '<div class="iikiti-embed iikiti-embed--video" data-aspect-ratio="'.
			htmlspecialchars($ratio, ENT_QUOTES).'">'.
			'<iframe src="https://player.vimeo.com/video/'.rawurlencode((string) $id).
			'" class="iikiti-embed__iframe" allowfullscreen loading="lazy" '.
			'referrerpolicy="no-referrer" title="Vimeo video"></iframe>'.
			'</div>';
	}

	private function extractId(string $url): ?string
	{
		if (preg_match('#vimeo\.com/(?:[0-9a-f]+/)?(\d+)#i', $url, $m)) {
			return $m[1];
		}

		return null;
	}
}
