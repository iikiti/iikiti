<?php

declare(strict_types=1);

namespace iikiti\Extension\Embeds\Embed\Provider;

use iikiti\CMS\Web\BlockEditor\Embed\EmbedProviderInterface;

/**
 * Direct media URLs (.mp4/.webm/.ogg/m4v) -> native <video>/<audio> element.
 */
final class GenericVideoProvider implements EmbedProviderInterface
{
	private const EXTENSIONS = ['mp4', 'webm', 'ogg', 'ogv', 'mov', 'm4v'];

	private const AUDIO_EXTENSIONS = ['mp3', 'wav', 'aac', 'oga', 'm4a'];

	public function supports(string $url): bool
	{
		$ext = strtolower((string) pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

		return in_array($ext, [...self::EXTENSIONS, ...self::AUDIO_EXTENSIONS], true);
	}

	public function render(string $url, array $options = []): string
	{
		$trimmed = trim($url);
		if (1 !== preg_match('#^https?://[a-z0-9.-]+#i', $trimmed)) {
			return '';
		}

		$ext = strtolower((string) pathinfo(parse_url($trimmed, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
		$tag = in_array($ext, self::AUDIO_EXTENSIONS, true) ? 'audio' : 'video';
		$ratio = (string) ($options['aspectRatio'] ?? '16:9');

		return '<div class="iikiti-embed iikiti-embed--video" data-aspect-ratio="'.
			htmlspecialchars($ratio, ENT_QUOTES).'">'.
			'<'.$tag.' src="'.htmlspecialchars($trimmed, ENT_QUOTES).'" controls'.
			' loading="lazy" title="Media"></'.$tag.'>'.
			'</div>';
	}
}
