<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\DigitalAsset\Usage\AssetUsageIndexInterface;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Value\LocaleTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Shared API serializer for digital assets used by both admin and editor endpoints
 * so payload shapes stay consistent across the entire CMS.
 */
final class DigitalAssetSerializer
{
	public function __construct(
		private readonly ?DigitalAssetVariantRepository $variants = null,
		private readonly ?LocalizedPropertyResolver $localizedProperties = null,
		private readonly ?EntityManagerInterface $entityManager = null,
		private readonly ?AssetUsageIndexInterface $usageIndex = null,
		#[Autowire('%asset_expiry_warning_days%')]
		private readonly int $expiryWarningDays = 14,
	) {
	}

	/**
	 * Serializes a list of assets while batch-loading all variants and usage counts in single queries.
	 *
	 * @param list<AbstractDigitalAsset> $assets
	 *
	 * @return list<array<string,mixed>>
	 */
	public function serializeMany(
		array $assets,
		?LocaleTag $requestedLocale = null,
		bool $includeWorkflowInstances = false,
	): array {
		if ([] === $assets) {
			return [];
		}

		$assetIds = [];
		foreach ($assets as $asset) {
			$id = $asset->getId();
			if (null !== $id) {
				$assetIds[] = $id;
			}
		}

		$variantsByAssetId = [];
		if (null !== $this->variants && [] !== $assetIds) {
			foreach ($this->variants->findByAssetIds($assetIds) as $variant) {
				$ownerId = $variant->getAsset()->getId();
				if (null !== $ownerId) {
					$variantsByAssetId[(string) $ownerId][] = $variant;
				}
			}
		}

		$usageCountsByAssetId = [];
		if (null !== $this->usageIndex && [] !== $assetIds) {
			$usageCountsByAssetId = $this->usageIndex->countUsageByAssetIds($assetIds);
		}

		$out = [];
		foreach ($assets as $asset) {
			$id = $asset->getId();
			$preloaded = null !== $id ? ($variantsByAssetId[(string) $id] ?? []) : [];
			$usageCount = null !== $id ? ($usageCountsByAssetId[(string) $id] ?? 0) : 0;
			$out[] = $this->serialize($asset, $requestedLocale, $includeWorkflowInstances, $preloaded, $usageCount);
		}

		return $out;
	}

	/**
	 * @param list<DigitalAssetVariant>|null $preloadedVariants   Pass `[]` to skip variant querying (e.g. brand-new upload)
	 * @param ?int                           $preloadedUsageCount Pass an integer to skip querying the usage index
	 *
	 * @return array<string,mixed>
	 */
	public function serialize(
		AbstractDigitalAsset $asset,
		?LocaleTag $requestedLocale = null,
		bool $includeWorkflowInstances = false,
		?array $preloadedVariants = null,
		?int $preloadedUsageCount = null,
	): array {
		$siteIds = [];
		foreach ($asset->getAccessSites() as $site) {
			$siteIds[] = $site->getId();
		}

		$publicToken = $asset->getPublicToken();
		$variantEntities = $preloadedVariants ?? (null !== $this->variants ? $this->variants->findByAsset($asset) : []);

		$variantData = [];
		$thumbnailUrl = null;
		$previewUrl = null;
		$posterWebpUrl = null;
		$posterUrl = null;
		foreach ($variantEntities as $variant) {
			$key = $variant->getKey();
			if (str_starts_with($key, 'hls_')) {
				continue;
			}
			$versionedUrl = self::buildAssetUrl($publicToken, $key, $variant->getChecksum());
			$variantData[] = [
				'key' => $key,
				'mimeType' => $variant->getMimeType(),
				'width' => $variant->getWidth(),
				'height' => $variant->getHeight(),
				'size' => $variant->getSize(),
				'checksum' => $variant->getChecksum(),
				'format' => $variant->getFormat(),
				'quality' => $variant->getQuality(),
				'focalCrop' => $variant->isFocalCrop(),
				'url' => '/assets/'.$publicToken.'/'.$key,
			];
			if ('thumbnail' === $key) {
				$thumbnailUrl = $versionedUrl;
			} elseif ('preview' === $key) {
				$previewUrl = $versionedUrl;
			} elseif ('poster_webp' === $key) {
				$posterWebpUrl = $versionedUrl;
			} elseif ('poster' === $key) {
				$posterUrl = $versionedUrl;
			}
		}

		$posterFallback = $posterWebpUrl ?? $posterUrl;
		if (null === $thumbnailUrl && null !== $posterFallback) {
			$thumbnailUrl = $posterFallback;
		}
		if (null === $previewUrl && null !== $posterFallback) {
			$previewUrl = $posterFallback;
		}

		$fileUrl = '/assets/'.$publicToken.'/original';
		$isImage = str_starts_with($asset->getMimeType(), 'image/');
		if (null === $thumbnailUrl && $isImage) {
			$thumbnailUrl = self::buildAssetUrl($publicToken, 'original', $asset->getChecksum());
		}
		if (null === $previewUrl && $isImage) {
			$previewUrl = self::buildAssetUrl($publicToken, 'original', $asset->getChecksum());
		}

		$defaultAlt = $this->serializeLocalizedProperty($asset, 'default_alt', $requestedLocale);
		$defaultCaption = $this->serializeLocalizedProperty($asset, 'default_caption', $requestedLocale);
		$description = $this->serializeLocalizedProperty($asset, 'description', $requestedLocale);

		$folder = $asset->getFolder();
		$tagIds = [];
		$tags = [];
		foreach ($asset->getTags() as $tag) {
			$tagId = $tag->getId();
			if (null !== $tagId) {
				$tagIds[] = $tagId;
			}
			$tags[] = [
				'id' => $tagId,
				'name' => $tag->getName(),
				'slug' => $tag->getSlug(),
			];
		}

		$collectionIds = [];
		foreach ($asset->getCollections() as $collection) {
			$collectionId = $collection->getId();
			if (null !== $collectionId) {
				$collectionIds[] = $collectionId;
			}
		}

		$assetId = $asset->getId();
		$usageCount = $preloadedUsageCount;
		if (null === $usageCount) {
			if (null !== $this->usageIndex && null !== $assetId) {
				$counts = $this->usageIndex->countUsageByAssetIds([$assetId]);
				$usageCount = $counts[(string) $assetId] ?? 0;
			} else {
				$usageCount = 0;
			}
		}

		$payload = [
			'id' => $assetId,
			'type' => $asset instanceof UploadedDigitalAsset ? 'upload' : 'external',
			'publicToken' => $publicToken,
			'title' => $asset->getTitle(),
			'mimeType' => $asset->getMimeType(),
			'originalName' => $asset->getOriginalName(),
			'size' => $asset->getSize(),
			'checksum' => $asset->getChecksum(),
			'uri' => $asset->getExternalUri(),
			'visibility' => $asset->getVisibility(),
			'siteIds' => $siteIds,
			'folderId' => $folder?->getId(),
			'folderPath' => $folder?->getPath(),
			'tagIds' => $tagIds,
			'tags' => $tags,
			'collectionIds' => $collectionIds,
			'processingStatus' => $asset->getProcessingStatus(),
			'currentVersion' => $asset->getCurrentVersion(),
			'usageCount' => $usageCount,
			'license' => [
				'type' => $asset->getLicenseType(),
				'holder' => $asset->getLicenseHolder(),
				'url' => $asset->getLicenseUrl(),
				'notes' => $asset->getLicenseNotes(),
				'expiresAt' => $asset->getExpiresAt()?->format(\DateTimeInterface::ATOM),
				'expiryAction' => $asset->getExpiryAction(),
				'isExpired' => $asset->isExpired(),
				'isExpiringSoon' => $asset->isExpiringSoon(max(1, $this->expiryWarningDays)),
			],
			'width' => $asset->getWidth(),
			'height' => $asset->getHeight(),
			'focalPoint' => [
				'x' => $asset->getEffectiveFocalX(),
				'y' => $asset->getEffectiveFocalY(),
			],
			'dominantColor' => $asset->getDominantColor(),
			'blurhash' => $asset->getBlurhash(),
			'durationMs' => $asset->getDurationMs(),
			'mediaMetadata' => $asset->getMediaMetadata(),
			'defaultAlt' => $defaultAlt['value'],
			'defaultAltLocale' => $defaultAlt['locale'],
			'defaultAltTranslations' => [] !== $defaultAlt['translations'] ? $defaultAlt['translations'] : new \stdClass(),
			'defaultCaption' => $defaultCaption['value'],
			'defaultCaptionLocale' => $defaultCaption['locale'],
			'defaultCaptionTranslations' => [] !== $defaultCaption['translations'] ? $defaultCaption['translations'] : new \stdClass(),
			'description' => $description['value'],
			'descriptionLocale' => $description['locale'],
			'descriptionTranslations' => [] !== $description['translations'] ? $description['translations'] : new \stdClass(),
			'variants' => $variantData,
			'fileUrl' => $fileUrl,
			'thumbnailUrl' => $thumbnailUrl,
			'previewUrl' => $previewUrl,
		];

		if ($includeWorkflowInstances) {
			$runs = [];
			if (null !== $this->entityManager) {
				$assetId = $asset->getId();
				if (null !== $assetId) {
					foreach ($this->entityManager->getRepository(WorkflowInstance::class)->findBySubject('digital_asset', (string) $assetId) as $instance) {
						$marking = $instance->getMarking();
						$runs[] = [
							'workflow' => $instance->getDefinitionVersion()->getDefinition()->getKey(),
							'version' => $instance->getDefinitionVersion()->getVersion(),
							'status' => 1 === count($marking) ? array_key_first($marking) : 'parallel',
							'executionState' => $instance->getExecutionState(),
							'attempts' => $instance->getAttempts(),
							'error' => $instance->getLastError(),
						];
					}
				}
			}
			$payload['workflowInstances'] = $runs;
		}

		return $payload;
	}

	public static function buildAssetUrl(string $publicToken, string $variant = 'original', ?string $checksum = null): string
	{
		$url = '/assets/'.$publicToken.'/'.$variant;
		if (null !== $checksum) {
			$normalized = strtolower(trim($checksum));
			if (1 === preg_match('/^[a-f0-9]{8,64}$/', $normalized)) {
				$url .= '?v='.substr($normalized, 0, 8);
			}
		}

		return $url;
	}

	/**
	 * @return array{value: ?string, locale: ?string, translations: array<string,string>}
	 */
	private function serializeLocalizedProperty(
		AbstractDigitalAsset $asset,
		string $propertyName,
		?LocaleTag $requestedLocale,
	): array {
		$property = $asset->getProperties()->get($propertyName);
		if (null === $property) {
			return ['value' => null, 'locale' => null, 'translations' => []];
		}

		$localized = $property->getLocalizedValue();
		if (null !== $localized) {
			$translations = $localized->getTranslations();
			$resolved = $this->localizedProperties?->resolve($asset, $property, $requestedLocale);
			if (null !== $resolved) {
				$resolvedValue = null;
				if (is_string($resolved->value)) {
					$resolvedValue = $resolved->value;
				} elseif (is_array($resolved->value)) {
					$resolvedValue = implode('', array_map(static fn (array $segment): string => $segment['text'], $resolved->value));
				}

				return [
					'value' => $resolvedValue,
					'locale' => $resolved->locale?->getTag(),
					'translations' => $translations,
				];
			}

			$firstLocale = array_key_first($translations);

			return [
				'value' => null !== $firstLocale ? $translations[$firstLocale] : null,
				'locale' => is_string($firstLocale) ? $firstLocale : null,
				'translations' => $translations,
			];
		}

		$raw = $property->getValue();

		return [
			'value' => is_string($raw) ? $raw : null,
			'locale' => null,
			'translations' => [],
		];
	}
}
