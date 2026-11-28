<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Media;

use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Service\DigitalAssetSerializer;
use iikiti\CMS\Service\LocalizedPropertyResolver;

/**
 * Normalizes block media source values, batch-prefetches DAM assets and variants
 * for a block tree without N+1 queries, and resolves responsive `<picture>` / `<img>`
 * render descriptors with immutable versioned URLs, focal-point `object-position`,
 * CLS dimensions, and localized alt/caption fallbacks.
 */
final class AssetRenderResolver
{
	private const MODERN_SOURCE_MIME_ORDER = [
		'image/avif',
		'image/webp',
	];

	private const ALLOWED_LOADING = ['lazy', 'eager'];
	private const ALLOWED_FETCHPRIORITY = ['auto', 'high', 'low'];

	public function __construct(
		private readonly ?DigitalAssetRepository $assets = null,
		private readonly ?DigitalAssetVariantRepository $variants = null,
		private readonly ?LocalizedPropertyResolver $localizedProperties = null,
	) {
	}

	/**
	 * Normalizes structured DAM asset objects, legacy URL objects, and bare URL
	 * strings into a canonical media reference structure.
	 *
	 * @return array{
	 *     mode: 'asset'|'url'|'empty',
	 *     assetId: ?int,
	 *     publicToken: ?string,
	 *     url: string,
	 *     focalPoint: ?array{x: float, y: float}
	 * }
	 */
	public static function normalizeSource(mixed $source): array
	{
		if (is_string($source)) {
			$url = self::sanitizeUrl($source);
			if ('' === $url) {
				return self::emptySource();
			}

			return [
				'mode' => 'url',
				'assetId' => null,
				'publicToken' => null,
				'url' => $url,
				'focalPoint' => null,
			];
		}

		if (!is_array($source)) {
			return self::emptySource();
		}

		$focalPoint = self::normalizeFocalPoint($source['focalPoint'] ?? null);
		$kind = is_string($source['source'] ?? null) ? strtolower(trim($source['source'])) : null;
		$assetId = self::normalizePositiveInt($source['assetId'] ?? null);
		$publicToken = self::normalizePublicToken($source['publicToken'] ?? null);
		$url = is_string($source['url'] ?? null) ? self::sanitizeUrl($source['url']) : '';

		if ('asset' === $kind || (null === $kind && null !== $assetId)) {
			if (null !== $assetId) {
				return [
					'mode' => 'asset',
					'assetId' => $assetId,
					'publicToken' => $publicToken,
					'url' => $url,
					'focalPoint' => $focalPoint,
				];
			}
			if ('' !== $url) {
				return [
					'mode' => 'url',
					'assetId' => null,
					'publicToken' => null,
					'url' => $url,
					'focalPoint' => $focalPoint,
				];
			}

			return self::emptySource();
		}

		if ('url' === $kind || (null === $kind && '' !== $url)) {
			if ('' === $url) {
				return self::emptySource();
			}

			return [
				'mode' => 'url',
				'assetId' => null,
				'publicToken' => null,
				'url' => $url,
				'focalPoint' => $focalPoint,
			];
		}

		return self::emptySource();
	}

	/**
	 * Recursively walks a block tree and collects all unique referenced DAM asset IDs
	 * so the renderer can batch-load them in 2 queries before Twig execution.
	 *
	 * @param array<mixed> $nodes
	 *
	 * @return list<int>
	 */
	public static function collectAssetIds(array $nodes): array
	{
		$ids = [];
		foreach ($nodes as $node) {
			if (!is_array($node)) {
				continue;
			}
			if (is_array($node['content'] ?? null)) {
				self::collectAssetIdsFromValue($node['content'], $ids);
			}
			if (is_array($node['children'] ?? null)) {
				foreach (self::collectAssetIds($node['children']) as $childId) {
					$ids[$childId] = true;
				}
			}
		}

		return array_keys($ids);
	}

	/**
	 * Batch-loads requested DAM assets granted to the given site together with their
	 * variants in exactly 2 queries (1 for assets, 1 for variants), enforcing site
	 * access and visibility rules and resolving localized default alt/caption text.
	 *
	 * @param list<int> $assetIds
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function prefetchForSite(array $assetIds, Site $site, bool $includePrivate = false): array
	{
		if (null === $this->assets) {
			return [];
		}

		$uniqueIds = [];
		foreach ($assetIds as $id) {
			$normalized = self::normalizePositiveInt($id);
			if (null !== $normalized) {
				$uniqueIds[$normalized] = $normalized;
			}
		}
		if ([] === $uniqueIds) {
			return [];
		}

		$loadedAssets = $this->assets->findAccessibleToSiteByIds(array_values($uniqueIds), $site);
		$eligible = [];
		$eligibleIds = [];
		foreach ($loadedAssets as $asset) {
			if (!$includePrivate && $asset->isEffectivelyPrivate()) {
				continue;
			}
			$id = self::normalizePositiveInt($asset->getId());
			if (null === $id) {
				continue;
			}
			$eligible[$id] = $asset;
			$eligibleIds[] = $id;
		}

		if ([] === $eligible) {
			return [];
		}

		$variantsByAssetId = [];
		if (null !== $this->variants) {
			foreach ($this->variants->findByAssetIds($eligibleIds) as $variant) {
				$ownerId = self::normalizePositiveInt($variant->getAsset()->getId());
				if (null !== $ownerId) {
					$variantsByAssetId[$ownerId][] = $variant;
				}
			}
		}

		$prefetched = [];
		foreach ($eligible as $id => $asset) {
			$prefetched[$id] = $this->buildAssetRecord($asset, $variantsByAssetId[$id] ?? []);
		}

		return $prefetched;
	}

	/**
	 * Converts a hydrated asset entity and its variants into a lightweight array record
	 * suitable for caching in render context and unit testing without ORM dependencies.
	 *
	 * @param list<DigitalAssetVariant> $variants
	 *
	 * @return array<string, mixed>
	 */
	public function buildAssetRecord(AbstractDigitalAsset $asset, array $variants = []): array
	{
		$variantRecords = [];
		foreach ($variants as $variant) {
			$variantRecords[] = [
				'key' => $variant->getKey(),
				'mimeType' => $variant->getMimeType(),
				'width' => $variant->getWidth(),
				'height' => $variant->getHeight(),
				'checksum' => $variant->getChecksum(),
				'format' => $variant->getFormat(),
				'focalCrop' => $variant->isFocalCrop(),
			];
		}

		return [
			'id' => self::normalizePositiveInt($asset->getId()),
			'publicToken' => $asset->getPublicToken(),
			'mimeType' => $asset->getMimeType(),
			'checksum' => $asset->getChecksum(),
			'visibility' => $asset->isEffectivelyPrivate() ? 'private' : $asset->getVisibility(),
			'width' => $asset->getWidth(),
			'height' => $asset->getHeight(),
			'focalPoint' => [
				'x' => $asset->getEffectiveFocalX(),
				'y' => $asset->getEffectiveFocalY(),
			],
			'dominantColor' => $asset->getDominantColor(),
			'blurhash' => $asset->getBlurhash(),
			'defaultAlt' => $this->resolveLocalizedPropertyText($asset, 'default_alt'),
			'defaultCaption' => $this->resolveLocalizedPropertyText($asset, 'default_caption'),
			'isExternal' => !$asset->isUploaded(),
			'variants' => $variantRecords,
		];
	}

	/**
	 * Resolves a block media source + render options into a complete `<picture>`/`<img>`
	 * render descriptor, or `null` when the source is empty, missing, or inaccessible.
	 *
	 * @param array<string, mixed>            $options
	 * @param array<int, array<string,mixed>> $prefetched
	 *
	 * @return array{
	 *     mode: 'asset'|'url',
	 *     src: string,
	 *     srcset: string,
	 *     sizes: string,
	 *     sources: list<array{type: string, srcset: string}>,
	 *     width: ?int,
	 *     height: ?int,
	 *     alt: string,
	 *     caption: string,
	 *     loading: string,
	 *     fetchpriority: string,
	 *     decoding: string,
	 *     objectPosition: string,
	 *     dominantColor: ?string,
	 *     blurhash: ?string,
	 *     isExternal: bool,
	 *     link: string
	 * }|null
	 */
	public function resolveForRender(
		mixed $source,
		array $options = [],
		array $prefetched = [],
		?Site $site = null,
		bool $includePrivate = false,
	): ?array {
		$normalized = self::normalizeSource($source);
		if ('empty' === $normalized['mode']) {
			return null;
		}

		$allowPrivate = $includePrivate || true === ($options['includePrivate'] ?? false);
		$decorative = true === ($options['decorative'] ?? false);
		$blockAlt = is_string($options['alt'] ?? null) ? trim($options['alt']) : '';
		$blockCaption = is_string($options['caption'] ?? null) ? trim($options['caption']) : '';
		$link = is_string($options['link'] ?? null) ? self::sanitizeUrl($options['link']) : '';
		$sizes = is_string($options['sizes'] ?? null) && '' !== trim($options['sizes']) ? trim($options['sizes']) : '100vw';
		$loadingRaw = is_string($options['loading'] ?? null) ? strtolower(trim($options['loading'])) : 'lazy';
		$loading = in_array($loadingRaw, self::ALLOWED_LOADING, true) ? $loadingRaw : 'lazy';
		$fetchPriorityRaw = is_string($options['fetchpriority'] ?? null) ? strtolower(trim($options['fetchpriority'])) : 'auto';
		$fetchpriority = in_array($fetchPriorityRaw, self::ALLOWED_FETCHPRIORITY, true) ? $fetchPriorityRaw : 'auto';
		$focalOverride = self::normalizeFocalPoint($options['focalPointOverride'] ?? $options['focalPoint'] ?? null);

		if ('url' === $normalized['mode']) {
			$url = $normalized['url'];
			$focalPoint = $focalOverride ?? $normalized['focalPoint'];
			$isExternal = str_starts_with(strtolower($url), 'https://') || str_starts_with(strtolower($url), 'http://');

			return [
				'mode' => 'url',
				'src' => $url,
				'srcset' => '',
				'sizes' => $sizes,
				'sources' => [],
				'width' => null,
				'height' => null,
				'alt' => $decorative ? '' : $blockAlt,
				'caption' => $blockCaption,
				'loading' => $loading,
				'fetchpriority' => $fetchpriority,
				'decoding' => 'async',
				'objectPosition' => self::formatObjectPosition($focalPoint),
				'dominantColor' => null,
				'blurhash' => null,
				'isExternal' => $isExternal,
				'link' => $link,
			];
		}

		$assetId = $normalized['assetId'];
		if (null === $assetId) {
			return null;
		}

		$record = $prefetched[$assetId] ?? null;
		if (!is_array($record) && null !== $site && null !== $this->assets) {
			$fetched = $this->prefetchForSite([$assetId], $site, $allowPrivate);
			$record = $fetched[$assetId] ?? null;
		}

		if (!is_array($record)) {
			return null;
		}

		$visibility = is_string($record['visibility'] ?? null) ? $record['visibility'] : 'public';
		if (!$allowPrivate && 'private' === $visibility) {
			return null;
		}

		$publicToken = is_string($record['publicToken'] ?? null) ? trim($record['publicToken']) : '';
		if ('' === $publicToken) {
			return null;
		}

		$assetChecksum = is_string($record['checksum'] ?? null) ? $record['checksum'] : null;
		$assetWidth = self::normalizePositiveInt($record['width'] ?? null);
		$assetHeight = self::normalizePositiveInt($record['height'] ?? null);
		$assetFocal = self::normalizeFocalPoint($record['focalPoint'] ?? null);
		$focalPoint = $focalOverride ?? $normalized['focalPoint'] ?? $assetFocal;

		$defaultAlt = is_string($record['defaultAlt'] ?? null) ? trim($record['defaultAlt']) : '';
		$defaultCaption = is_string($record['defaultCaption'] ?? null) ? trim($record['defaultCaption']) : '';

		$alt = $decorative ? '' : ('' !== $blockAlt ? $blockAlt : $defaultAlt);
		$caption = '' !== $blockCaption ? $blockCaption : $defaultCaption;

		$rawVariants = is_array($record['variants'] ?? null) ? $record['variants'] : [];
		$resolvedVariants = $this->buildResponsiveSources($publicToken, $assetChecksum, $rawVariants);

		$width = $assetWidth ?? $resolvedVariants['fallbackWidth'];
		$height = $assetHeight ?? $resolvedVariants['fallbackHeight'];

		return [
			'mode' => 'asset',
			'src' => $resolvedVariants['src'],
			'srcset' => $resolvedVariants['srcset'],
			'sizes' => $sizes,
			'sources' => $resolvedVariants['sources'],
			'width' => $width,
			'height' => $height,
			'alt' => $alt,
			'caption' => $caption,
			'loading' => $loading,
			'fetchpriority' => $fetchpriority,
			'decoding' => 'async',
			'objectPosition' => self::formatObjectPosition($focalPoint),
			'dominantColor' => is_string($record['dominantColor'] ?? null) ? $record['dominantColor'] : null,
			'blurhash' => is_string($record['blurhash'] ?? null) ? $record['blurhash'] : null,
			'isExternal' => true === ($record['isExternal'] ?? false),
			'link' => $link,
		];
	}

	public static function buildAssetUrl(string $publicToken, string $variant = 'original', ?string $checksum = null): string
	{
		return DigitalAssetSerializer::buildAssetUrl($publicToken, $variant, $checksum);
	}

	/** @param ?array{x: float, y: float} $focalPoint */
	public static function formatObjectPosition(?array $focalPoint): string
	{
		if (null === $focalPoint) {
			return '50% 50%';
		}

		return self::formatPercent($focalPoint['x']).' '.self::formatPercent($focalPoint['y']);
	}

	/**
	 * @param array<mixed> $rawVariants
	 *
	 * @return array{
	 *     src: string,
	 *     srcset: string,
	 *     sources: list<array{type: string, srcset: string}>,
	 *     fallbackWidth: ?int,
	 *     fallbackHeight: ?int
	 * }
	 */
	private function buildResponsiveSources(string $publicToken, ?string $assetChecksum, array $rawVariants): array
	{
		/** @var array<string, array<int, array{url: string, width: int, height: ?int, isResponsive: bool}>> $modernByMime */
		$modernByMime = [];
		/** @var array<int, array{url: string, width: int, height: ?int, isResponsive: bool}> $fallbackByWidth */
		$fallbackByWidth = [];
		/** @var array{url: string, mimeType: string, width: ?int, height: ?int}|null $previewVariant */
		$previewVariant = null;

		foreach ($rawVariants as $variant) {
			if (!is_array($variant)) {
				continue;
			}
			$key = is_string($variant['key'] ?? null) ? trim($variant['key']) : '';
			$mime = is_string($variant['mimeType'] ?? null) ? strtolower(trim($variant['mimeType'])) : '';
			if ('' === $key || '' === $mime) {
				continue;
			}

			$checksum = is_string($variant['checksum'] ?? null) ? $variant['checksum'] : null;
			$width = self::normalizePositiveInt($variant['width'] ?? null);
			$height = self::normalizePositiveInt($variant['height'] ?? null);
			$focalCrop = true === ($variant['focalCrop'] ?? false);
			$url = self::buildAssetUrl($publicToken, $key, $checksum);
			$isResponsiveKey = 1 === preg_match('/^w\d+_[a-z0-9]+$/i', $key);

			if ('preview' === $key && !$focalCrop) {
				$previewVariant = [
					'url' => $url,
					'mimeType' => $mime,
					'width' => $width,
					'height' => $height,
				];
			}

			// Never mix focal-cropped variants into uncropped responsive srcset candidates.
			if ($focalCrop || null === $width) {
				continue;
			}

			if (in_array($mime, self::MODERN_SOURCE_MIME_ORDER, true)) {
				if (!isset($modernByMime[$mime][$width]) || $isResponsiveKey) {
					$modernByMime[$mime][$width] = [
						'url' => $url,
						'width' => $width,
						'height' => $height,
						'isResponsive' => $isResponsiveKey,
					];
				}
				continue;
			}

			if ($isResponsiveKey) {
				$fallbackByWidth[$width] = [
					'url' => $url,
					'width' => $width,
					'height' => $height,
					'isResponsive' => true,
				];
			}
		}

		$sources = [];
		foreach (self::MODERN_SOURCE_MIME_ORDER as $mime) {
			if (!isset($modernByMime[$mime]) || [] === $modernByMime[$mime]) {
				continue;
			}
			ksort($modernByMime[$mime], SORT_NUMERIC);
			$descriptors = [];
			foreach ($modernByMime[$mime] as $entry) {
				$descriptors[] = $entry['url'].' '.$entry['width'].'w';
			}
			$sources[] = [
				'type' => $mime,
				'srcset' => implode(', ', $descriptors),
			];
		}

		$fallbackSrcset = '';
		$fallbackSrc = null;
		$fallbackWidth = null;
		$fallbackHeight = null;

		if ([] !== $fallbackByWidth) {
			ksort($fallbackByWidth, SORT_NUMERIC);
			$descriptors = [];
			foreach ($fallbackByWidth as $entry) {
				$descriptors[] = $entry['url'].' '.$entry['width'].'w';
			}
			$fallbackSrcset = implode(', ', $descriptors);

			// Pick the largest fallback responsive variant <= 1280px as default <img> src,
			// falling back to the smallest available variant.
			$bestEntry = null;
			foreach ($fallbackByWidth as $entry) {
				if ($entry['width'] <= 1280) {
					$bestEntry = $entry;
				} elseif (null === $bestEntry) {
					$bestEntry = $entry;
					break;
				}
			}
			if (null !== $bestEntry) {
				$fallbackSrc = $bestEntry['url'];
				$fallbackWidth = $bestEntry['width'];
				$fallbackHeight = $bestEntry['height'];
			}
		}

		if (null !== $previewVariant && !in_array($previewVariant['mimeType'], self::MODERN_SOURCE_MIME_ORDER, true)) {
			$fallbackSrc = $previewVariant['url'];
			$fallbackWidth ??= $previewVariant['width'];
			$fallbackHeight ??= $previewVariant['height'];
		} elseif (null === $fallbackSrc && null !== $previewVariant) {
			$fallbackSrc = $previewVariant['url'];
			$fallbackWidth ??= $previewVariant['width'];
			$fallbackHeight ??= $previewVariant['height'];
		}

		if (null === $fallbackSrc) {
			$fallbackSrc = self::buildAssetUrl($publicToken, 'original', $assetChecksum);
		}

		return [
			'src' => $fallbackSrc,
			'srcset' => $fallbackSrcset,
			'sources' => $sources,
			'fallbackWidth' => $fallbackWidth,
			'fallbackHeight' => $fallbackHeight,
		];
	}

	private function resolveLocalizedPropertyText(AbstractDigitalAsset $asset, string $propertyName): ?string
	{
		$property = $asset->getProperties()->get($propertyName);
		if (null === $property) {
			return null;
		}

		$localized = $property->getLocalizedValue();
		if (null !== $localized) {
			$resolved = $this->localizedProperties?->resolve($asset, $property);
			if (null !== $resolved) {
				if (is_string($resolved->value)) {
					$trimmed = trim($resolved->value);

					return '' !== $trimmed ? $trimmed : null;
				}
				if (is_array($resolved->value)) {
					$joined = trim(implode('', array_map(static fn (array $segment): string => $segment['text'], $resolved->value)));

					return '' !== $joined ? $joined : null;
				}
			}

			$translations = $localized->getTranslations();
			$firstLocale = array_key_first($translations);
			if (null !== $firstLocale) {
				$trimmed = trim($translations[$firstLocale]);

				return '' !== $trimmed ? $trimmed : null;
			}

			return null;
		}

		$raw = $property->getValue();
		if (is_string($raw)) {
			$trimmed = trim($raw);

			return '' !== $trimmed ? $trimmed : null;
		}

		return null;
	}

	/**
	 * @param array<int, true> $ids
	 */
	private static function collectAssetIdsFromValue(mixed $value, array &$ids): void
	{
		if (!is_array($value)) {
			return;
		}

		if (
			isset($value['source'])
			&& is_string($value['source'])
			&& 'asset' === strtolower(trim($value['source']))
			&& array_key_exists('assetId', $value)
		) {
			$assetId = self::normalizePositiveInt($value['assetId']);
			if (null !== $assetId) {
				$ids[$assetId] = true;
			}

			return;
		}

		foreach ($value as $nested) {
			if (is_array($nested)) {
				self::collectAssetIdsFromValue($nested, $ids);
			}
		}
	}

	/** @return array{mode: 'empty', assetId: null, publicToken: null, url: string, focalPoint: null} */
	private static function emptySource(): array
	{
		return [
			'mode' => 'empty',
			'assetId' => null,
			'publicToken' => null,
			'url' => '',
			'focalPoint' => null,
		];
	}

	private static function normalizePositiveInt(mixed $value): ?int
	{
		if (is_int($value)) {
			return $value > 0 ? $value : null;
		}
		if (is_string($value)) {
			$trimmed = trim($value);
			if ('' !== $trimmed && ctype_digit($trimmed)) {
				$parsed = (int) $trimmed;

				return $parsed > 0 ? $parsed : null;
			}
		}

		return null;
	}

	private static function normalizePublicToken(mixed $value): ?string
	{
		if (!is_string($value)) {
			return null;
		}
		$trimmed = strtolower(trim($value));

		return 1 === preg_match('/^[a-f0-9]{64}$/', $trimmed) ? $trimmed : null;
	}

	/** @return ?array{x: float, y: float} */
	private static function normalizeFocalPoint(mixed $value): ?array
	{
		if (!is_array($value)) {
			return null;
		}
		if (!array_key_exists('x', $value) || !array_key_exists('y', $value)) {
			return null;
		}
		$rawX = $value['x'];
		$rawY = $value['y'];
		if ((!is_int($rawX) && !is_float($rawX) && !is_numeric($rawX)) || (!is_int($rawY) && !is_float($rawY) && !is_numeric($rawY))) {
			return null;
		}
		$x = (float) $rawX;
		$y = (float) $rawY;
		if (!is_finite($x) || !is_finite($y)) {
			return null;
		}

		return [
			'x' => max(0.0, min(1.0, $x)),
			'y' => max(0.0, min(1.0, $y)),
		];
	}

	public static function sanitizeUrl(string $url): string
	{
		$trimmed = trim($url);
		if ('' === $trimmed || 1 === preg_match('/[\x00-\x1f\x7f]/', $trimmed)) {
			return '';
		}
		if (str_starts_with($trimmed, '/')) {
			return str_starts_with($trimmed, '//') ? '' : $trimmed;
		}

		$parts = parse_url($trimmed);
		if (!is_array($parts)) {
			return '';
		}
		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
			return '';
		}

		return $trimmed;
	}

	private static function formatPercent(float $ratio): string
	{
		$clamped = max(0.0, min(1.0, $ratio));
		$formatted = rtrim(rtrim(sprintf('%.2f', $clamped * 100.0), '0'), '.');

		return ('' === $formatted || '-0' === $formatted ? '0' : $formatted).'%';
	}
}
