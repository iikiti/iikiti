<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Embed\Provider;

use iikiti\CMS\Web\BlockEditor\Embed\EmbedProviderInterface;

/**
 * YouTube watch / short URLs -> embed iframe.
 */
final class YouTubeProvider implements EmbedProviderInterface
{
	public function supports(string $url): bool
	{
		return (bool) preg_match(
			'~^(https?://)(?:www\.)?(?:youtube\.com/(?:watch\?v=|embed/|shorts/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})(?:[/?#]|$)~',
			$url
		);
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
			'<iframe src="https://www.youtube.com/embed/'.rawurlencode($id).
			'" class="iikiti-embed__iframe" allowfullscreen loading="lazy" '.
			'referrerpolicy="no-referrer" title="YouTube video"></iframe>'.
			'</div>';
	}

	private function extractId(string $url): ?string
	{
		if (preg_match('~youtu\.be/([A-Za-z0-9_-]{11})~', $url, $m)) {
			return $m[1];
		}
		if (preg_match('~(?:youtube\.com/(?:watch\?v=|shorts/|embed/|v/)|youtube\.com/v/)([A-Za-z0-9_-]{11})~', $url, $m)) {
			return $m[1];
		}
		if (preg_match('~/embed/([A-Za-z0-9_-]{11})~', $url, $m)) {
			return $m[1];
		}

		return null;
	}
}
