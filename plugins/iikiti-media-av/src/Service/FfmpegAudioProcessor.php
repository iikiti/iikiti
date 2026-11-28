<?php

declare(strict_types=1);

namespace iikiti\Extension\MediaAv\Service;

use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use iikiti\CMS\Service\DigitalAssetProcessorInterface;

/**
 * Audio-scoped processor alias delegating to {@see FfmpegMediaProcessor}.
 */
final readonly class FfmpegAudioProcessor implements DigitalAssetProcessorInterface
{
	public const KEY = 'ffmpeg_audio';

	public function __construct(
		private FfmpegMediaProcessor $mediaProcessor,
	) {
	}

	public function getKey(): string
	{
		return self::KEY;
	}

	public function supportsMimePattern(string $mimePattern): bool
	{
		$normalized = strtolower(trim($mimePattern));

		return ('audio/*' === $normalized || str_starts_with($normalized, 'audio/'))
			&& $this->mediaProcessor->supportsMimePattern($normalized);
	}

	public function process(AbstractDigitalAsset $asset, AssetWorkflowDefinition $workflow): void
	{
		$this->mediaProcessor->process($asset, $workflow);
	}
}
