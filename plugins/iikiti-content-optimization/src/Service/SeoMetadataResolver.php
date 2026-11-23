<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Service;

final class SeoMetadataResolver
{
	public function __construct(
		private readonly ContentOptimizationSettings $settings,
		private readonly PageSeoSettingsNormalizer $pageSettingsNormalizer,
	) {
	}

	/**
	 * Resolve display-ready SEO values without producing markup or encoding.
	 *
	 * Stable output fields are title, description, canonicalUrl, robots.index,
	 * robots.follow, openGraph.title/description/type/image/url, and
	 * twitter.card/title/description/image, and structuredData. Callers must escape
	 * values for their output context (including Twig escaping or JSON_HEX flags for
	 * JSON).
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
	 *     openGraph: array{title: string, description: string, siteName: string, type: string, image: string, url: string},
	 *     twitter: array{card: string, title: string, description: string, image: string},
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
		$openGraphImage = $this->pageValue($page, 'iikiti_content_optimization_og_image');
		if ('' === $openGraphImage) {
			$openGraphImage = $this->stringValue($seoDefaults['defaultSocialImage'] ?? '');
		}
		$openGraphImage = $this->resolveUrl($openGraphImage, $pageUrl);

		$twitterImage = $this->inherit($this->pageValue($page, 'iikiti_content_optimization_twitter_image'), $openGraphImage);
		$twitterImage = $this->resolveUrl($twitterImage, $pageUrl);
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
				'image' => $openGraphImage,
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
				'image' => $twitterImage,
			],
			'structuredData' => $structuredData,
		];
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

	/** @param array<string, string> $page */
	private function pageValue(array $page, string $key): string
	{
		return $page[$key] ?? '';
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
