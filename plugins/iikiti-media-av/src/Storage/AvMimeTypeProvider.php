<?php

declare(strict_types=1);

namespace iikiti\Extension\MediaAv\Storage;

use iikiti\CMS\Storage\AssetMimeTypeDefinition;
use iikiti\CMS\Storage\AssetMimeTypeProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Registers user-uploadable audio and video MIME types for the iikiti-media-av plugin.
 *
 * HLS playlists (.m3u8) and transport segments (.ts) are intentionally excluded from
 * user-uploadable types so untrusted manifests cannot be uploaded directly.
 */
final readonly class AvMimeTypeProvider implements AssetMimeTypeProviderInterface
{
	public const DEFAULT_MAX_UPLOAD_BYTES = 262144000;

	public function __construct(
		#[Autowire('%media_av_max_upload_bytes%')]
		private int $maxUploadBytes = self::DEFAULT_MAX_UPLOAD_BYTES,
	) {
		if ($this->maxUploadBytes < 1) {
			throw new \InvalidArgumentException('MEDIA_AV_MAX_UPLOAD_BYTES must be a positive integer.');
		}
	}

	/**
	 * @return list<AssetMimeTypeDefinition>
	 */
	public function getDefinitions(): array
	{
		return [
			new AssetMimeTypeDefinition('video/mp4', 'mp4', 'video', $this->maxUploadBytes, true),
			new AssetMimeTypeDefinition('video/webm', 'webm', 'video', $this->maxUploadBytes, true),
			new AssetMimeTypeDefinition('audio/mpeg', 'mp3', 'audio', $this->maxUploadBytes, true),
			new AssetMimeTypeDefinition('audio/ogg', 'ogg', 'audio', $this->maxUploadBytes, true),
			new AssetMimeTypeDefinition('audio/wav', 'wav', 'audio', $this->maxUploadBytes, true),
			new AssetMimeTypeDefinition('audio/mp4', 'm4a', 'audio', $this->maxUploadBytes, true),
			new AssetMimeTypeDefinition('audio/webm', 'weba', 'audio', $this->maxUploadBytes, true),
		];
	}
}
