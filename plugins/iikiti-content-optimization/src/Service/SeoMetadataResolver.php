<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Service;

use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DigitalAssetVariant;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Repository\DigitalAssetVariantRepository;
use iikiti\CMS\Service\DigitalAssetSerializer;
use iikiti\CMS\Service\LocalizedPropertyResolver;

final class SeoMetadataResolver
{
	/** Social crawlers reliably support JPEG, PNG, and GIF (preferring JPEG/PNG over AVIF). */
	private const PREFERRED_SOCIAL_MIMES = ['image/jpeg', 'image/png', 'image/gif'];

	public function __construct(
		private readonly ContentOptimizationSettings $settings,
		private readonly PageSeoSettingsNormalizer $pageSettingsNormalizer,
		private readonly ?DigitalAssetRepository $digitalAssets = null,
		private readonly ?DigitalAssetVariantRepository $digitalAssetVariants = null,
		private readonly ?LocalizedPropertyResolver $localizedProperties = null,
	) {
	}

	/**
	 * Resolve display-ready SEO values without producing markup or encoding.
	 *
	 * Stable output fields are title, description, canonicalUrl, robots.index,
	 * robots.follow, openGraph.title/description/siteName/type/image/imageWidth/
	 * imageHeight/imageAlt/url, twitter.card/title/description/image/imageWidth/
	 * imageHeight/imageAlt, and structuredData. Callers must escape values for
	 * their output context (including Twig escaping or JSON_HEX flags for JSON).
	 *
	 * @param array<string, mixed> $applicationDefaults Validated application settings
	 * @param array<string, mixed> $siteOverrides       Validated partial site overrides
	 * @param array<string, mixed> $pageSettings        Validated page SEO settings
	 *
	 * @return array{
	 *     title: string,
	 *     description: string,
	 *     canonicalUrl: string,
	 *     robots: array{index: string, follow: string},
	 *     openGraph: array{
	 *         title: string,
	 *         description: string,
	 *         siteName: string,
	 *         type: string,
	 *         image: string,
	 *         imageWidth: ?int,
	 *         imageHeight: ?int,
	 *         imageAlt: string,
	 *         url: string
	 *     },
	 *     twitter: array{
	 *         card: string,
	 *         title: string,
	 *         description: string,
	 *         image: string,
	 *         imageWidth: ?int,
	 *         imageHeight: ?int,
	 *         imageAlt: string
	 *     },
	 *     structuredData: list<array<string,mixed>>
	 * }
	 */
	public function resolve(
		array $applicationDefaults,
		array $siteOverrides,
		array $pageSettings,
		string $pageTitle,
		string $pageUrl,
		string $siteName,
		?Site $site = null,
	): array {
		$effective = $this->settings->resolve($applicationDefaults, $siteOverrides)['effective'];
		$seoDefaults = is_array($effective['seo'] ?? null) ? $effective['seo'] : [];
		try {
			$page = $this->pageSettingsNormalizer->normalize($pageSettings);
		} catch (\InvalidArgumentException) {
			// Invalid legacy SEO values must not turn a public route into a 500 or index unsafe content.
			$page = [
				'iikiti_content_optimization_robots_index' => 'noindex',
				'iikiti_content_optimization_robots_follow' => 'nofollow',
			];
		}

		$resolvedSite = $site ?? $this->currentSite();

		$seoTitle = $this->pageValue($page, 'iikiti_content_optimization_title');
		$title = '' !== $seoTitle ?
			$seoTitle :
			$this->resolveTitleTemplate($this->stringValue($seoDefaults['titleTemplate'] ?? ''), $pageTitle, $siteName);
		$description = $this->inherit(
			$this->pageValue($page, 'iikiti_content_optimization_description'),
			$this->stringValue($seoDefaults['defaultDescription'] ?? ''),
		);

		$canonicalUrl = $this->pageValue($page, 'iikiti_content_optimization_canonical_url');
		if ('' === $canonicalUrl) {
			$canonicalUrl = $pageUrl;
		} else {
			$canonicalUrl = $this->resolveUrl($canonicalUrl, $pageUrl);
		}

		$defaultRobots = is_array($seoDefaults['defaultRobots'] ?? null) ? $seoDefaults['defaultRobots'] : [];
		$robots = [
			'index' => $this->inherit(
				$this->pageValue($page, 'iikiti_content_optimization_robots_index'),
				$this->stringValue($defaultRobots['index'] ?? 'index'),
			),
			'follow' => $this->inherit(
				$this->pageValue($page, 'iikiti_content_optimization_robots_follow'),
				$this->stringValue($defaultRobots['follow'] ?? 'follow'),
			),
		];

		$openGraphTitle = $this->inherit($this->pageValue($page, 'iikiti_content_optimization_og_title'), $title);
		$openGraphDescription = $this->inherit($this->pageValue($page, 'iikiti_content_optimization_og_description'), $description);

		$openGraphMedia = $this->resolveSocialImage($page['iikiti_content_optimization_og_image'] ?? '', $pageUrl, $resolvedSite);
		if ('' === $openGraphMedia['image']) {
			$openGraphMedia = $this->resolveSocialImage($seoDefaults['defaultSocialImage'] ?? '', $pageUrl, $resolvedSite);
		}

		$twitterMedia = $this->resolveSocialImage($page['iikiti_content_optimization_twitter_image'] ?? '', $pageUrl, $resolvedSite);
		if ('' === $twitterMedia['image']) {
			$twitterMedia = $openGraphMedia;
		}

		$structuredData = $this->buildStructuredData($pageTitle, $siteName, $pageUrl, $canonicalUrl);

		return [
			'title' => $title,
			'description' => $description,
			'canonicalUrl' => $canonicalUrl,
			'robots' => $robots,
			'openGraph' => [
				'title' => $openGraphTitle,
				'description' => $openGraphDescription,
				'siteName' => $siteName,
				'type' => $this->inherit($this->pageValue($page, 'iikiti_content_optimization_og_type'), 'website'),
				'image' => $openGraphMedia['image'],
				'imageWidth' => $openGraphMedia['imageWidth'],
				'imageHeight' => $openGraphMedia['imageHeight'],
				'imageAlt' => $openGraphMedia['imageAlt'],
				'url' => $canonicalUrl,
			],
			'twitter' => [
				'card' => $this->inherit($this->pageValue($page, 'iikiti_content_optimization_twitter_card'), 'summary_large_image'),
				'title' => $this->inherit(
					$this->pageValue($page, 'iikiti_content_optimization_twitter_title'),
					$this->inherit($openGraphTitle, $title),
				),
				'description' => $this->inherit(
					$this->pageValue($page, 'iikiti_content_optimization_twitter_description'),
					$this->inherit($openGraphDescription, $description),
				),
				'image' => $twitterMedia['image'],
				'imageWidth' => $twitterMedia['imageWidth'],
				'imageHeight' => $twitterMedia['imageHeight'],
				'imageAlt' => $twitterMedia['imageAlt'],
			],
			'structuredData' => $structuredData,
		];
	}

	/**
	 * @return array{image: string, imageWidth: ?int, imageHeight: ?int, imageAlt: string}
	 */
	private function resolveSocialImage(mixed $source, string $pageUrl, ?Site $site): array
	{
		$empty = ['image' => '', 'imageWidth' => null, 'imageHeight' => null, 'imageAlt' => ''];
		if (is_string($source)) {
			$trimmed = trim($source);
			if ('' === $trimmed) {
				return $empty;
			}

			return [
				'image' => $this->resolveUrl($trimmed, $pageUrl),
				'imageWidth' => null,
				'imageHeight' => null,
				'imageAlt' => '',
			];
		}

		if (!is_array($source)) {
			return $empty;
		}

		$kind = is_string($source['source'] ?? null) ? strtolower(trim($source['source'])) : '';
		if ('url' === $kind) {
			$url = is_string($source['url'] ?? null) ? trim($source['url']) : '';
			if ('' === $url) {
				return $empty;
			}

			return [
				'image' => $this->resolveUrl($url, $pageUrl),
				'imageWidth' => null,
				'imageHeight' => null,
				'imageAlt' => '',
			];
		}

		if ('asset' !== $kind || null === $this->digitalAssets) {
			return $empty;
		}

		$assetId = $source['assetId'] ?? null;
		if (!is_int($assetId) || $assetId < 1) {
			return $empty;
		}

		$asset = null !== $site
			? $this->digitalAssets->findAccessibleToSite($assetId, $site)
			: $this->digitalAssets->find($assetId);

		// Never expose private or ungranted assets to unauthenticated social crawlers.
		if (!$asset instanceof AbstractDigitalAsset || 'public' !== $asset->getVisibility()) {
			return $empty;
		}

		$variants = null !== $this->digitalAssetVariants
			? $this->digitalAssetVariants->findByAsset($asset)
			: [];

		$selected = $this->selectSocialVariant($asset, $variants);
		$relativeUrl = DigitalAssetSerializer::buildAssetUrl($asset->getPublicToken(), $selected['key'], $selected['checksum']);

		return [
			'image' => $this->resolveUrl($relativeUrl, $pageUrl),
			'imageWidth' => $selected['width'] ?? $asset->getWidth(),
			'imageHeight' => $selected['height'] ?? $asset->getHeight(),
			'imageAlt' => $this->resolveAssetDefaultAlt($asset),
		];
	}

	/**
	 * Picks the best social-crawler-safe image variant, preferring JPEG/PNG/GIF
	 * (`preview`, then `w1280_jpg`/`w1280_png` or closest responsive variant, then
	 * `original`) over WebP/AVIF for maximum compatibility with Open Graph and
	 * Twitter Card scrapers.
	 *
	 * @param list<DigitalAssetVariant> $variants
	 *
	 * @return array{key: string, width: ?int, height: ?int, checksum: ?string}
	 */
	private function selectSocialVariant(AbstractDigitalAsset $asset, array $variants): array
	{
		$previewPreferred = null;
		$previewWebp = null;
		/** @var list<DigitalAssetVariant> $preferredResponsive */
		$preferredResponsive = [];
		/** @var list<DigitalAssetVariant> $webpResponsive */
		$webpResponsive = [];

		foreach ($variants as $variant) {
			if ($variant->isFocalCrop()) {
				continue;
			}
			$key = $variant->getKey();
			$mime = strtolower(trim($variant->getMimeType()));
			if ('preview' === $key) {
				if (in_array($mime, self::PREFERRED_SOCIAL_MIMES, true)) {
					$previewPreferred = $variant;
				} elseif ('image/webp' === $mime) {
					$previewWebp = $variant;
				}
				continue;
			}

			if (1 === preg_match('/^w\d+_[a-z0-9]+$/i', $key) && null !== $variant->getWidth()) {
				if (in_array($mime, self::PREFERRED_SOCIAL_MIMES, true)) {
					$preferredResponsive[] = $variant;
				} elseif ('image/webp' === $mime) {
					$webpResponsive[] = $variant;
				}
			}
		}

		if (null !== $previewPreferred) {
			return $this->variantDescriptor($previewPreferred);
		}

		$bestPreferred = $this->pickClosestToTargetWidth($preferredResponsive, 1280);
		if (null !== $bestPreferred) {
			return $this->variantDescriptor($bestPreferred);
		}

		if (in_array(strtolower(trim($asset->getMimeType())), self::PREFERRED_SOCIAL_MIMES, true)) {
			return [
				'key' => 'original',
				'width' => $asset->getWidth(),
				'height' => $asset->getHeight(),
				'checksum' => $asset->getChecksum(),
			];
		}

		if (null !== $previewWebp) {
			return $this->variantDescriptor($previewWebp);
		}

		$bestWebp = $this->pickClosestToTargetWidth($webpResponsive, 1280);
		if (null !== $bestWebp) {
			return $this->variantDescriptor($bestWebp);
		}

		return [
			'key' => 'original',
			'width' => $asset->getWidth(),
			'height' => $asset->getHeight(),
			'checksum' => $asset->getChecksum(),
		];
	}

	/**
	 * @param list<DigitalAssetVariant> $variants
	 */
	private function pickClosestToTargetWidth(array $variants, int $targetWidth): ?DigitalAssetVariant
	{
		if ([] === $variants) {
			return null;
		}

		$best = null;
		$bestDistance = PHP_INT_MAX;
		foreach ($variants as $variant) {
			$width = $variant->getWidth();
			if (null === $width || $width < 1) {
				continue;
			}
			$distance = abs($width - $targetWidth);
			if (
				null === $best
				|| $distance < $bestDistance
				|| ($distance === $bestDistance && $width > (int) $best->getWidth())
			) {
				$best = $variant;
				$bestDistance = $distance;
			}
		}

		return $best;
	}

	/** @return array{key: string, width: ?int, height: ?int, checksum: ?string} */
	private function variantDescriptor(DigitalAssetVariant $variant): array
	{
		return [
			'key' => $variant->getKey(),
			'width' => $variant->getWidth(),
			'height' => $variant->getHeight(),
			'checksum' => $variant->getChecksum(),
		];
	}

	private function resolveAssetDefaultAlt(AbstractDigitalAsset $asset): string
	{
		$property = $asset->getProperties()->get('default_alt');
		if (null === $property) {
			return '';
		}

		$localized = $property->getLocalizedValue();
		if (null !== $localized) {
			$resolved = $this->localizedProperties?->resolve($asset, $property);
			if (null !== $resolved) {
				if (is_string($resolved->value)) {
					return trim($resolved->value);
				}
				if (is_array($resolved->value)) {
					return trim(implode('', array_map(static fn (array $segment): string => $segment['text'], $resolved->value)));
				}
			}

			$translations = $localized->getTranslations();
			$firstLocale = array_key_first($translations);

			return null !== $firstLocale ? trim($translations[$firstLocale]) : '';
		}

		$raw = $property->getValue();

		return is_string($raw) ? trim($raw) : '';
	}

	private function currentSite(): ?Site
	{
		try {
			return SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null;
		} catch (\Throwable) {
			return null;
		}
	}

	/** @return list<array<string,mixed>> */
	private function buildStructuredData(string $pageTitle, string $siteName, string $pageUrl, string $canonicalUrl): array
	{
		$siteRoot = $this->siteRoot($pageUrl);
		if ('' === $siteName || '' === $siteRoot) {
			return [];
		}

		$organizationId = $siteRoot.'#organization';
		$websiteId = $siteRoot.'#website';
		$graph = [
			[
				'@type' => 'Organization',
				'@id' => $organizationId,
				'name' => $siteName,
				'url' => $siteRoot,
			],
			[
				'@type' => 'WebSite',
				'@id' => $websiteId,
				'url' => $siteRoot,
				'name' => $siteName,
				'publisher' => ['@id' => $organizationId],
			],
		];

		if ('' !== trim($pageTitle) && '' !== $canonicalUrl) {
			$items = [
				['@type' => 'ListItem', 'position' => 1, 'name' => $siteName, 'item' => $siteRoot],
			];
			if ($canonicalUrl !== $siteRoot) {
				$items[] = ['@type' => 'ListItem', 'position' => 2, 'name' => $pageTitle, 'item' => $canonicalUrl];
			}
			$graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
		}

		return [['@context' => 'https://schema.org', '@graph' => $graph]];
	}

	private function siteRoot(string $pageUrl): string
	{
		$parts = parse_url($pageUrl);
		if (
			false === $parts ||
			!isset($parts['scheme'], $parts['host']) ||
			!in_array(strtolower($parts['scheme']), ['http', 'https'], true) ||
			isset($parts['user']) ||
			isset($parts['pass'])
		) {
			return '';
		}

		$origin = strtolower($parts['scheme']).'://'.$parts['host'];
		if (isset($parts['port'])) {
			$origin .= ':'.$parts['port'];
		}

		return $origin.'/';
	}

	/** @param array<string, string|array<string, mixed>> $page */
	private function pageValue(array $page, string $key): string
	{
		$value = $page[$key] ?? '';

		return is_string($value) ? $value : '';
	}

	private function stringValue(mixed $value): string
	{
		return is_string($value) ? $value : '';
	}

	private function inherit(string $value, string $fallback): string
	{
		return '' !== $value ? $value : $fallback;
	}

	private function resolveTitleTemplate(string $template, string $pageTitle, string $siteName): string
	{
		if ('' === trim($template)) {
			return $pageTitle;
		}

		$title = trim(strtr($template, ['{pageTitle}' => $pageTitle, '{siteName}' => $siteName]));

		return '' !== $title ? $title : $pageTitle;
	}

	private function resolveUrl(string $url, string $pageUrl): string
	{
		if ('' === $url || !str_starts_with($url, '/')) {
			return $url;
		}

		$parts = parse_url($pageUrl);
		if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
			return $url;
		}

		$origin = $parts['scheme'].'://'.$parts['host'];
		if (isset($parts['port'])) {
			$origin .= ':'.$parts['port'];
		}

		return $origin.$url;
	}
}
