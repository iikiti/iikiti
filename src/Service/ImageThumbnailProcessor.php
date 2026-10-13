<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ImageThumbnailProcessor implements DigitalAssetProcessorInterface
{
	public function __construct(
		private readonly AssetStorage $storage,
		private readonly DigitalAssetVariantRepository $variants,
		private readonly EntityManagerInterface $entityManager,
		private readonly Filesystem $filesystem = new Filesystem(),
	) {
	}

	public function getKey(): string
	{
		return 'image_thumbnail';
	}

	public function supportsMimePattern(string $mimePattern): bool
	{
		return 'image/*' === $mimePattern;
	}

	public function process(AbstractDigitalAsset $asset, AssetWorkflowDefinition $workflow): void
	{
		$sourceKey = $asset->getStorageKey();
		if (null === $sourceKey) {
			throw new \RuntimeException('Uploaded image has no stored file.');
		}
		$source = $this->storage->pathFor($sourceKey);
		$dimensions = @getimagesize($source);
		if (false === $dimensions || $dimensions[0] > 20000 || $dimensions[1] > 20000 || $dimensions[0] * $dimensions[1] > 100000000) {
			throw new \RuntimeException('Image dimensions are invalid or exceed the processing limit.');
		}
		$conditions = $workflow->getConditions();
		if (($dimensions[0] < (int) ($conditions['min_width'] ?? 0)) || ($dimensions[1] < (int) ($conditions['min_height'] ?? 0))) {
			return;
		}

		$mimeType = $asset->getMimeType();
		$extension = match ($mimeType) {
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
			default => throw new \InvalidArgumentException('Unsupported image MIME type.'),
		};
		$variants = $workflow->getOptions()['variants'] ?? [['name' => 'thumbnail', 'width' => 320, 'height' => 320]];
		if (!is_array($variants) || [] === $variants) {
			throw new \InvalidArgumentException('At least one image variant is required.');
		}

		foreach ($variants as $variant) {
			if (!is_array($variant)) {
				throw new \InvalidArgumentException('Image variant configuration must be an object.');
			}
			$name = (string) ($variant['name'] ?? '');
			$width = filter_var($variant['width'] ?? null, FILTER_VALIDATE_INT);
			$height = filter_var($variant['height'] ?? null, FILTER_VALIDATE_INT);
			if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $name) || false === $width || false === $height || $width < 1 || $height < 1 || $width > 4096 || $height > 4096) {
				throw new \InvalidArgumentException('Image variant name or dimensions are invalid.');
			}

			$temporaryPath = $this->storage->createTemporaryPath($extension);
			try {
				$process = new Process([
					'magick',
					'-limit', 'memory', '256MiB',
					'-limit', 'map', '512MiB',
					'-limit', 'disk', '1GiB',
					$source.'[0]',
					'-auto-orient',
					'-thumbnail',
					$width.'x'.$height.'>',
					'-strip',
					$temporaryPath,
				]);
				$process->setTimeout(30)->mustRun();
				$outputDimensions = @getimagesize($temporaryPath);
				if (false === $outputDimensions) {
					throw new \RuntimeException('Thumbnail generation produced an invalid image.');
				}
				$storageKey = $this->storage->storeTemporaryFile($temporaryPath, $mimeType);
				$existing = $this->variants->findByAssetAndKey($asset, $name);
				if (null !== $existing) {
					$this->entityManager->remove($existing);
				}
				$this->entityManager->persist(new DigitalAssetVariant(
					$asset,
					$name,
					$storageKey,
					$mimeType,
					filesize($this->storage->pathFor($storageKey)) ?: 0,
					$outputDimensions[0],
					$outputDimensions[1],
				));
				try {
					$this->entityManager->flush();
				} catch (\Throwable $exception) {
					$this->storage->delete($storageKey);
					throw $exception;
				}
				if (null !== $existing) {
					$this->storage->delete($existing->getStorageKey());
				}
			} finally {
				$this->filesystem->remove($temporaryPath);
			}
		}
	}
}
