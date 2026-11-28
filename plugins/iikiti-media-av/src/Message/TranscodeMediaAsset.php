<?php

declare(strict_types=1);

namespace iikiti\Extension\MediaAv\Message;

/**
 * Messenger command triggering asynchronous HLS transcoding for a digital asset.
 */
final readonly class TranscodeMediaAsset
{
	public function __construct(
		public int|string $assetId,
	) {
	}
}
