<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Web;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Service\LocalizedPropertyResolver;
use iikiti\CMS\Value\LocaleTag;
use iikiti\CMS\Web\BlockEditor\EditorSettingsManager;
use iikiti\CMS\Web\BlockEditor\PageRenderContribution;
use iikiti\CMS\Web\BlockEditor\PageRenderContributorInterface;
use iikiti\CMS\Web\Fragment\FragmentRuntime;
use iikiti\Extension\ContentOptimization\Analytics\AnalyticsDescriptorResolver;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use iikiti\Extension\ContentOptimization\Service\SeoMetadataResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contributes SEO/OpenGraph/Twitter/JSON-LD `<head>` tags and privacy-aware
 * analytics bootstrap payloads + loader script when active on a site.
 */
final class ContentOptimizationPageRenderContributor implements PageRenderContributorInterface
{
	public const PLUGIN_SLUG = 'iikiti-content-optimization';

	public function __construct(
		private readonly ApplicationRegistry $applications,
		private readonly ContentOptimizationSettings $settings,
		private readonly SeoMetadataResolver $seoMetadataResolver,
		private readonly EditorSettingsManager $settingsManager,
		private readonly ?AnalyticsDescriptorResolver $analyticsDescriptorResolver = null,
		private readonly ?LocalizedPropertyResolver $localizedPropertyResolver = null,
		private readonly ?PluginActivationChecker $activationChecker = null,
		private readonly ?FragmentRuntime $fragmentRuntime = null,
	) {
	}

	public function contribute(
		Request $request,
		?Site $site,
		?DbObject $object,
		Template $template,
		bool $editorMode,
		array $state,
	): ?PageRenderContribution {
		if (!$site instanceof Site) {
			return null;
		}
		$active = null !== $this->activationChecker
			? $this->activationChecker->isActiveForSite(self::PLUGIN_SLUG, $site)
			: in_array(self::PLUGIN_SLUG, $site->getEnabledPlugins(), true);
		if (!$active) {
			return null;
		}

		$siteName = $this->siteName($site, $request);
		$baseTitle = $this->pageTitle($template, $request, $siteName);
		$pageTitle = $object instanceof Page ? $this->pageTitle($object, $request, $baseTitle) : $baseTitle;
		$pageUrl = $this->pageUrl($request, $site);

		$resolved = is_array($state['resolved_optimization'] ?? null)
			? $state['resolved_optimization']
			: $this->resolveOptimizationSettings($site, $editorMode);

		$publishedPageSettings = is_array($state['published_page_settings'] ?? null) ? $state['published_page_settings'] : [];
		$publishedHomeSettings = is_array($state['published_home_settings'] ?? null)
			? $state['published_home_settings']
			: ((true === ($state['home'] ?? false) && null === $object)
				? $this->seoPageSettings($this->settingsManager->publishedSettings($site))
				: []);

		$pageSettings = $editorMode ? [] : (
			$object instanceof Page
				? $publishedPageSettings
				: (true === ($state['home'] ?? false) ? $publishedHomeSettings : [])
		);

		$metadata = $this->seoMetadataResolver->resolve(
			$resolved['defaults'],
			$resolved['overrides'],
			$pageSettings,
			$pageTitle,
			$pageUrl,
			$siteName,
		);
		$openGraphLocale = $this->openGraphLocale($request);
		if (null !== $openGraphLocale) {
			$metadata['locale'] = $openGraphLocale;
		}
		if ($editorMode) {
			$metadata['robots'] = ['index' => 'noindex', 'follow' => 'nofollow'];
		}

		$headHtml = [];
		$description = $metadata['description'];
		if ('' !== $description) {
			$headHtml[] = sprintf('<meta name="description" content="%s">', $this->escAttr($description));
		}
		$canonicalUrl = $metadata['canonicalUrl'];
		if ('' !== $canonicalUrl) {
			$headHtml[] = sprintf('<link rel="canonical" href="%s">', $this->escAttr($canonicalUrl));
		}
		$robotsIndex = $metadata['robots']['index'];
		$robotsFollow = $metadata['robots']['follow'];
		$headHtml[] = sprintf('<meta name="robots" content="%s">', $this->escAttr($robotsIndex.','.$robotsFollow));

		$og = $metadata['openGraph'];
		foreach ([
			'og:title' => $og['title'],
			'og:description' => $og['description'],
			'og:site_name' => $og['siteName'],
			'og:type' => $og['type'],
			'og:locale' => (string) ($metadata['locale'] ?? ''),
			'og:image' => $og['image'],
			'og:url' => $og['url'],
		] as $property => $value) {
			if ('' !== $value) {
				$headHtml[] = sprintf('<meta property="%s" content="%s">', $property, $this->escAttr($value));
			}
		}

		$tw = $metadata['twitter'];
		$headHtml[] = sprintf('<meta name="twitter:card" content="%s">', $this->escAttr($tw['card']));
		foreach ([
			'twitter:title' => $tw['title'],
			'twitter:description' => $tw['description'],
			'twitter:image' => $tw['image'],
		] as $name => $value) {
			if ('' !== $value) {
				$headHtml[] = sprintf('<meta name="%s" content="%s">', $name, $this->escAttr($value));
			}
		}

		$structuredData = $metadata['structuredData'];
		if ([] !== $structuredData) {
			$ldJson = json_encode(
				$structuredData,
				JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
			);
			$headHtml[] = '<script type="application/ld+json">'.$ldJson.'</script>';
		}

		$jsonBlocks = [];
		$consentActive = true === ($state['iikiti_consent_enabled'] ?? false)
			|| (null !== $this->fragmentRuntime && $this->fragmentRuntime->isAvailable('consent-management.banner', $site))
			|| (null !== $this->activationChecker && $this->activationChecker->isActiveForSite('consent-management', $site));
		if (null !== $this->analyticsDescriptorResolver) {
			$analyticsConfig = $this->analyticsDescriptorResolver->resolve(
				$site,
				$resolved['effective'],
				$consentActive,
				$editorMode,
			);
			if ([] !== $analyticsConfig['connectors']) {
				$jsonBlocks['iikiti-analytics-config'] = $analyticsConfig;
				$headHtml[] = '<script type="module" src="/plugin-assets/iikiti-content-optimization/dist/analytics-loader.js"></script>';
			}
		}

		return new PageRenderContribution(
			title: $metadata['title'],
			headHtml: $headHtml,
			jsonBlocks: $jsonBlocks,
			templateVars: ['iikiti_suppress_editor_noindex' => true],
		);
	}

	/**
	 * @return array{defaults:array<string,mixed>,overrides:array<string,mixed>,effective:array<string,mixed>}
	 */
	public function resolveOptimizationSettings(Site $site, bool $editorMode): array
	{
		if ($editorMode) {
			$defaults = $this->settings->defaults();

			return ['defaults' => $defaults, 'overrides' => [], 'effective' => $defaults];
		}

		$application = $this->applications->hasCurrentApplication() ? $this->applications->getCurrent() : null;
		$defaults = null === $application
			? $this->settings->defaults()
			: $this->settings->applicationDefaults($application);
		$overrides = $this->settings->siteOverrides($site);
		$effective = $this->settings->resolve($defaults, $overrides)['effective'];

		return ['defaults' => $defaults, 'overrides' => $overrides, 'effective' => $effective];
	}

	/**
	 * @param array<string,mixed> $settings
	 *
	 * @return array<string,mixed>
	 */
	public function seoPageSettings(array $settings): array
	{
		return array_filter(
			$settings,
			static fn ($value, string $key): bool => str_starts_with($key, 'iikiti_content_optimization_'),
			ARRAY_FILTER_USE_BOTH,
		);
	}

	private function escAttr(string $value): string
	{
		// Match Twig `escape('html_attr')` safety (escape quotes, angle brackets, ampersands, spaces, colons, slashes, parens).
		$escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		return str_replace(
			[' ', ':', '/', '(', ')'],
			['&#x20;', '&#x3A;', '&#x2F;', '&#x28;', '&#x29;'],
			$escaped,
		);
	}

	private function openGraphLocale(Request $request): ?string
	{
		$locale = $this->localeTag($request);
		if (null === $locale) {
			return null;
		}
		$parsed = \Locale::parseLocale($locale->getTag());
		if (!is_array($parsed)) {
			return null;
		}
		$language = $parsed['language'] ?? null;
		$region = $parsed['region'] ?? null;
		if (!is_string($language) || !is_string($region) || 1 !== preg_match('/^[A-Za-z]{2}$/D', $region)) {
			return null;
		}

		return strtolower($language).'_'.strtoupper($region);
	}

	private function pageTitle(DbObject $object, Request $request, string $fallback): string
	{
		return $this->localizedProperty($object, 'title', $request) ?? $fallback;
	}

	private function siteName(Site $site, Request $request): string
	{
		$name = $this->localizedProperty($site, 'name', $request);
		$domain = $site->getProperties()->get(Site::DOMAIN_PROPERTY_KEY)?->getValue();

		return $name ?? (is_string($domain) && '' !== trim($domain) ? trim($domain) : 'iikiti');
	}

	private function localizedProperty(DbObject $object, string $name, Request $request): ?string
	{
		$property = $object->getProperties()->get($name);
		if (null === $property) {
			return null;
		}

		$value = $property->getValue();
		if (null !== $this->localizedPropertyResolver && null !== ($locale = $this->localeTag($request))) {
			$value = $this->localizedPropertyResolver->resolve($object, $property, $locale)->value ?? $value;
		}

		return is_string($value) && '' !== trim($value) ? trim($value) : null;
	}

	private function localeTag(Request $request): ?LocaleTag
	{
		try {
			return LocaleTag::tryFromString(str_replace('_', '-', $request->getLocale()));
		} catch (\InvalidArgumentException) {
			return null;
		}
	}

	private function pageUrl(Request $request, Site $site): string
	{
		$domain = $site->getProperties()->get(Site::DOMAIN_PROPERTY_KEY)?->getValue();
		$domainUrl = is_string($domain) ? 'https://'.$domain : '';
		$parsedDomain = '' !== $domainUrl ? parse_url($domainUrl) : false;
		if (
			!is_string($domain)
			|| 1 !== preg_match('/^(?:\[[0-9a-f:]+\]|[a-z0-9.-]+)(?::[0-9]{1,5})?$/iD', $domain)
			|| false === $parsedDomain
			|| false === filter_var($domainUrl, FILTER_VALIDATE_URL)
		) {
			return '';
		}

		$scheme = $request->getScheme();
		if (!in_array($scheme, ['http', 'https'], true)) {
			$scheme = 'https';
		}
		$path = $request->getPathInfo();
		if (!str_starts_with($path, '/')) {
			$path = '/'.$path;
		}

		return $scheme.'://'.$domain.$path;
	}
}
