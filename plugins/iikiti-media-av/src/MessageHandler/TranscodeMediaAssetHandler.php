<?php

declare(strict_types=1);

namespace iikiti\Extension\MediaAv\MessageHandler;

use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\Extension\MediaAv\Message\TranscodeMediaAsset;
use iikiti\Extension\MediaAv\Service\HlsTranscoder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles asynchronous HLS transcoding requests on the `media_transcoding` queue.
 */
#[AsMessageHandler]
final readonly class TranscodeMediaAssetHandler
{
	public function __construct(
		private DigitalAssetRepository $assets,
		private HlsTranscoder $transcoder,
		private ?PluginActivationChecker $activationChecker = null,
	) {
	}

	public function __invoke(TranscodeMediaAsset $message): void
	{
		if (null !== $this->activationChecker && !$this->activationChecker->isActive('iikiti-media-av')) {
			return;
		}

		$asset = $this->assets->find($message->assetId);
		if (!$asset instanceof UploadedDigitalAsset) {
			return;
		}

		$this->transcoder->transcode($asset);
	}
}
