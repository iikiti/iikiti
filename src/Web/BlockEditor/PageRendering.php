<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use Doctrine\Common\Collections\ArrayCollection;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Filters\InlineAssetMinificationOptionsResolver;
use iikiti\CMS\Profiler\ProfilerQueryDiagnostics;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use iikiti\CMS\Service\LocalizedPropertyResolver;
use iikiti\CMS\Value\LocaleTag;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\CustomCssCollector;
use iikiti\CMS\Web\Fragment\FragmentContext;
use iikiti\CMS\Web\Fragment\FragmentRuntime;
use iikiti\CMS\Web\Fragment\PageFragmentResult;
use iikiti\CMS\Web\Template\TemplateRenderer;
use iikiti\CMS\Web\Template\TemplateResolutionContext;
use iikiti\CMS\Web\Template\TemplateResolver;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use iikiti\Extension\ContentOptimization\Analytics\AnalyticsDescriptorResolver;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use iikiti\Extension\ContentOptimization\Service\SeoMetadataResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Renders a live front-end page through the template/block pipeline.
 *
 * Used by HomeController (no object, `home` context) and ObjectController
 * (an object page, e.g. a Page). It selects published trees for visitors and
 * draft trees for editors, merges in the object's dynamic-block content, and
 * injects the editor bootstrap config (via {@see FrontendConfigProvider}) when
 * the visitor is authorised.
 */
final class PageRendering
{
	public function __construct(
		private readonly \iikiti\CMS\Web\Icon\IconFontAssets $iconFontAssets,
		private readonly TemplateResolver $resolver,
		private readonly TemplateRenderer $templateRenderer,
		private readonly FrontendConfigProvider $configProvider,
		private readonly EditorSettingsManager $settingsManager,
		private readonly CustomCssCompiler $customCssCompiler,
		private readonly Security $security,
		private readonly Environment $twig,
		private readonly InlineAssetMinificationOptionsResolver $inlineAssetOptions,
		private readonly ApplicationRegistry $applications,
		private readonly ?FragmentRuntime $fragmentRuntime = null,
		private readonly ?AnalyticsDescriptorResolver $analyticsDescriptorResolver = null,
		private readonly ?WorkflowAccessPolicy $workflowAccessPolicy = null,
		private readonly ?LocalizedPropertyResolver $localizedPropertyResolver = null,
		private readonly ?ContentOptimizationSettings $contentOptimizationSettings = null,
		private readonly ?SeoMetadataResolver $seoMetadataResolver = null,
		private readonly ?HomepageSeoAccessPolicy $homepageSeoAccessPolicy = null,
	) {
	}

	public function render(Request $request, ?DbObject $object = null, bool $home = false): Response
	{
		$state = $this->prepareRenderState($request, $object, $home);
		$template = $state['template'];
		$renderContext = $state['render_context'];
		$customCss = $state['custom_css'];
		$layout = $this->baseLayout($request, $state);
		$composer = new HtmlFragmentComposer($layout);
		$this->templateRenderer->renderIntoDocument(
			$template,
			$renderContext,
			$state['region_trees'],
			$composer,
			'[data-iikiti-fragment="page"]',
			resolutionContext: $state['resolution_context'],
		);

		$iconFont = $this->iconFontAssets->shouldLink($state['can_edit']) ?
			$this->twig->render('base/_icon_font_link.twig') :
			'';
		$composer->insertIntoPlaceholder('[data-iikiti-fragment="icon-font"]', $iconFont);

		$composer->insertIntoPlaceholder('[data-iikiti-fragment="custom-css"]', $this->customCssHtml($customCss, $state['editor_mode']));

		return new DomHtmlResponse($composer->document());
	}

	/**
	 * Returns a server-rendered layout skeleton and fixed API fragment descriptors.
	 *
	 * @return array{html:string,styles:list<array{id:?string,css:string}>,editor_mode:bool,revision:string,fragments:list<array{kind:string,id:string,role:string,slot:string}>,inline_asset_minification:array{css_minify:bool,js_minify:bool,js_uglify:bool}}
	 */
	public function renderManifest(Request $request, ?DbObject $object = null, bool $home = false): array
	{
		$state = $this->prepareRenderState($request, $object, $home);
		$manifest = $this->templateRenderer->renderManifest(
			$state['template'],
			$state['render_context'],
			$state['region_trees'],
			$state['resolution_context'],
		);
		// Render the layout skeleton directly with Twig instead of parsing and
		// re-serializing a temporary HTMLDocument on the API side.
		$html = $this->baseLayout($request, $state, [
			'iikiti_use_fragment_composer' => false,
			'iikiti_custom_css_placeholder' => true,
			'iikiti_page_body' => $manifest['html'],
			'iikiti_icon_font' => $this->iconFontAssets->shouldLink($state['can_edit']),
		]);

		return [
			'html' => $html,
			'styles' => $state['custom_css']->fragments($state['editor_mode']),
			'editor_mode' => $state['editor_mode'],
			'revision' => $manifest['revision'],
			'fragments' => $manifest['fragments'],
			'inline_asset_minification' => $this->inlineAssetOptions->resolve(
				$request,
				SiteRegistry::isInitialized() && SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null,
			)->toArray(),
		];
	}

	public function renderFragment(Request $request, ?DbObject $object, bool $home, string $kind, string $id, ?string $expectedRevision = null): ?PageFragmentResult
	{
		$state = $this->prepareRenderState($request, $object, $home, includePageStyles: false, forFragment: true);

		return $this->templateRenderer->renderFragment(
			$state['template'],
			$state['render_context'],
			$state['region_trees'],
			$kind,
			$id,
			$state['resolution_context'],
			$expectedRevision,
		);
	}

	/**
	 * @return array{
	 *   template:Template,
	 *   resolution_context:TemplateResolutionContext,
	 *   render_context:BlockRenderContext,
	 *   region_trees:array<string,list<array<string,mixed>>>,
	 *   custom_css:CustomCssCollector,
	 *   editor_mode:bool,
	 *   can_edit:bool,
	 *   config:mixed,
	 *   site:?Site,
	 *   object:?DbObject,
	 *   home:bool,
	 *   published_page_settings:array<string,mixed>,
	 *   published_home_settings:array<string,mixed>,
	 *   scope_classes:list<string>
	 * }
	 */
	private function prepareRenderState(Request $request, ?DbObject $object, bool $home, bool $includePageStyles = true, bool $forFragment = false): array
	{
		$site = SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null;
		$resolutionContext = new TemplateResolutionContext(
			site: $site,
			object: $object,
			objectType: null === $object ? null : $object::class,
			home: $home,
		);
		$template = $this->resolver->resolve($resolutionContext) ?? $this->defaultTemplate();
		if (true === $request->attributes->get(ProfilerQueryDiagnostics::RENDER_CONTEXT_ENABLED_ATTRIBUTE)) {
			$request->attributes->set(
				ProfilerQueryDiagnostics::RENDER_CONTEXT_ATTRIBUTE,
				$this->pageTemplateProfilerContext($request, $template),
			);
		}
		$user = $this->security->getUser();
		$canEdit = $user instanceof User && $this->configProvider->canEdit($user);
		$editorMode = $canEdit && $request->query->has('edit');
		$resolvedOptimization = $forFragment
			? ['defaults' => [], 'overrides' => [], 'effective' => []]
			: $this->resolveOptimizationSettings($site, $editorMode);
		$settingsContext = !$forFragment && $canEdit
			? $this->settingsContext($object, $template, $user, $home, $site, $request, $resolvedOptimization)
			: null;
		$config = !$forFragment && $canEdit ?
			$this->configProvider->build(
				contextId: (int) ($template->getId() ?? 0),
				contextType: 'template',
				user: $user,
				// The current draft version — the editor echoes it back as
				// `If-Match` on save; a missing version would 409 every save.
				version: $template->getDraftVersion(),
				settingsContext: $settingsContext ?? [],
			) :
			null;
		$regionTrees = ($editorMode && $template->getBlocksDraft() ? $template->getBlocksDraft() : ($template->getBlocks() ?: []));
		$customCss = new CustomCssCollector();
		$pageSettings = !$forFragment && $object instanceof Page ?
			($editorMode && 'page' === ($settingsContext['type'] ?? null) ?
				$settingsContext['values'] :
				$this->settingsManager->publishedSettings($object)) :
			[];
		$publishedPageSettings = !$forFragment && $object instanceof Page && !$editorMode ? $pageSettings : [];
		$publishedHomeSettings = !$forFragment && $home && null === $object && null !== $site && !$editorMode ?
			$this->seoPageSettings($this->settingsManager->publishedSettings($site)) :
			[];
		if ($includePageStyles) {
			$templateSettings = $editorMode && 'template' === ($settingsContext['type'] ?? null) ?
				$settingsContext['values'] :
				$this->settingsManager->publishedSettings($template);
			$this->appendCustomCss(
				$customCss,
				$templateSettings,
				$this->scopeClass('template', $template->getId()),
				$editorMode ? $this->customCssStyleId('template', $template->getId()) : null,
			);
			if ($object instanceof Page) {
				$this->appendCustomCss(
					$customCss,
					$pageSettings,
					$this->scopeClass('page', $object->getId()),
					$editorMode ? $this->customCssStyleId('page', $object->getId()) : null,
				);
			}
		}

		$renderContext = new BlockRenderContext(
			editorMode: $editorMode,
			canEdit: $canEdit,
			site: $site,
			object: $object,
			request: $request,
			dynamicBlocks: $this->dynamicBlocksFor($object, $editorMode),
			customCssCollector: $customCss,
		);

		$this->twig->addGlobal('iikiti_can_edit', $canEdit);
		$this->twig->addGlobal('iikiti_editor_mode', $editorMode);
		$this->twig->addGlobal('iikiti_config', $forFragment ? null : $config);

		return [
			'template' => $template,
			'resolution_context' => $resolutionContext,
			'render_context' => $renderContext,
			'region_trees' => $regionTrees,
			'custom_css' => $customCss,
			'editor_mode' => $editorMode,
			'can_edit' => $canEdit,
			'config' => $config,
			'site' => $site,
			'object' => $object,
			'home' => $home,
			'published_page_settings' => $publishedPageSettings,
			'published_home_settings' => $publishedHomeSettings,
			'resolved_optimization' => $resolvedOptimization,
			'scope_classes' => array_values(array_filter([
				$this->scopeClass('template', $template->getId()),
				$object instanceof Page ? $this->scopeClass('page', $object->getId()) : null,
			])),
		];
	}

	private function pageTemplateProfilerContext(Request $request, Template $template): string
	{
		$title = trim((string) $template->getTitle());
		if ('' === $title) {
			$title = null !== $template->getId() ? sprintf('template #%d', $template->getId()) : 'default template';
		}

		return sprintf('Page "%s" | Template "%s" | Twig "%s"', $request->getPathInfo(), $title, $template->getLayout());
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $overrides
	 */
	private function baseLayout(Request $request, array $state, array $overrides = []): string
	{
		$consentBanner = $this->consentBanner($request, $state);
		$consentProviderActive = '' !== $consentBanner;
		$seo = $this->seoMetadata($request, $state, $consentProviderActive);
		$config = $state['config'];
		$context = [
			'doc' => ['title' => $seo['title']],
			'iikiti_page_locale' => $this->publicLocale($request),
			'iikiti_page_body' => '',
			'iikiti_editor_noindex' => $state['editor_mode'],
			'iikiti_forma_theme' => in_array($state['template']->getLayout(), [Template::DEFAULT_LAYOUT, Template::LEGACY_DEFAULT_LAYOUT], true),
			'iikiti_use_fragment_composer' => true,
			'iikiti_editor_mode' => $state['editor_mode'],
			'iikiti_can_edit' => $state['can_edit'],
			'iikiti_config' => $config,
			'iikiti_config_json' => null !== $config && [] !== $config ? json_encode(
				$config,
				JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
			) : null,
			'iikiti_icon_font' => false,
			'iikiti_scope_classes' => $state['scope_classes'],
			'iikiti_custom_css' => '',
			'iikiti_consent_enabled' => $consentProviderActive,
		];
		if ($seo['enabled']) {
			$context['iikiti_seo'] = $seo;
			if (isset($seo['analyticsConfigJson'])) {
				$context['iikiti_analytics_config_json'] = $seo['analyticsConfigJson'];
			}
		}
		if ($consentProviderActive) {
			$context['iikiti_consent_banner'] = $consentBanner;
		}

		return $this->twig->render('base/layout.twig', array_replace($context, $overrides));
	}

	/** @param array<string,mixed> $state */
	private function consentBanner(Request $request, array $state): string
	{
		$site = $state['site'];
		if (
			$state['editor_mode'] ||
			!$site instanceof Site ||
			!isset($this->fragmentRuntime) ||
			!$this->fragmentRuntime->isAvailable('consent-management.banner', $site)
		) {
			return '';
		}

		return $this->fragmentRuntime->render(
			'consent-management.banner',
			new FragmentContext($site, $request->getLocale()),
		)->html;
	}

	/**
	 * @param array<string,mixed> $state
	 *
	 * @return array<string,mixed>
	 */
	private function seoMetadata(Request $request, array $state, bool $consentProviderActive = false): array
	{
		$site = $state['site'];
		if (
			null === $site ||
			!isset($this->contentOptimizationSettings, $this->seoMetadataResolver) ||
			!in_array(HomepageSeoAccessPolicy::CONTENT_OPTIMIZATION_PLUGIN_SLUG, $site->getEnabledPlugins(), true)
		) {
			return [
				'enabled' => false,
				'title' => $state['template']->getTitle() ?? 'iikiti',
			];
		}
		$siteName = $this->siteName($site, $request);
		$object = $state['object'];
		$pageTitle = $object instanceof Page ?
			$this->pageTitle($object, $request, $this->pageTitle($state['template'], $request, $siteName)) :
			$this->pageTitle($state['template'], $request, $siteName);
		$pageUrl = $this->pageUrl($request, $site);
		$editorMode = (bool) $state['editor_mode'];

		$resolvedOptimization = is_array($state['resolved_optimization'] ?? null)
			? $state['resolved_optimization']
			: $this->resolveOptimizationSettings($site, $editorMode);
		$applicationDefaults = $resolvedOptimization['defaults'];
		$siteOverrides = $resolvedOptimization['overrides'];
		$effectiveSettings = $resolvedOptimization['effective'];
		$pageSettings = $editorMode ? [] : (
			$object instanceof Page ?
				$state['published_page_settings'] :
				(true === ($state['home'] ?? false) ? ($state['published_home_settings'] ?? []) : [])
		);

		$metadata = $this->seoMetadataResolver->resolve(
			$applicationDefaults,
			$siteOverrides,
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
		if (isset($this->analyticsDescriptorResolver)) {
			$analyticsConfig = $this->analyticsDescriptorResolver->resolve(
				$site,
				$effectiveSettings,
				$consentProviderActive,
				$editorMode,
			);
			if ([] !== $analyticsConfig['connectors']) {
				$metadata['analyticsConfigJson'] = json_encode(
					$analyticsConfig,
					JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
				);
			}
		}
		$metadata['enabled'] = true;
		$metadata['structuredDataJson'] = json_encode(
			$metadata['structuredData'],
			JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
		);

		return $metadata;
	}

	/**
	 * @return array{defaults:array<string,mixed>,overrides:array<string,mixed>,effective:array<string,mixed>}
	 */
	private function resolveOptimizationSettings(?Site $site, bool $editorMode): array
	{
		if (!isset($this->contentOptimizationSettings) || null === $site || !in_array(HomepageSeoAccessPolicy::CONTENT_OPTIMIZATION_PLUGIN_SLUG, $site->getEnabledPlugins(), true)) {
			return ['defaults' => [], 'overrides' => [], 'effective' => []];
		}

		if ($editorMode) {
			$defaults = $this->contentOptimizationSettings->defaults();

			return ['defaults' => $defaults, 'overrides' => [], 'effective' => $defaults];
		}

		$application = $this->applications->hasCurrentApplication() ? $this->applications->getCurrent() : null;
		$defaults = null === $application ?
			$this->contentOptimizationSettings->defaults() :
			$this->contentOptimizationSettings->applicationDefaults($application);
		$overrides = $this->contentOptimizationSettings->siteOverrides($site);
		$effective = $this->contentOptimizationSettings->resolve($defaults, $overrides)['effective'];

		return ['defaults' => $defaults, 'overrides' => $overrides, 'effective' => $effective];
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
		$title = $this->localizedProperty($object, 'title', $request);

		return $title ?? $fallback;
	}

	private function siteName(?Site $site, Request $request): string
	{
		if (null === $site) {
			return 'iikiti';
		}

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
		if (isset($this->localizedPropertyResolver) && null !== $locale = $this->localeTag($request)) {
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

	private function publicLocale(Request $request): string
	{
		return $this->localeTag($request)?->getTag() ?? 'en';
	}

	private function pageUrl(Request $request, ?Site $site): string
	{
		if (null === $site) {
			return '';
		}

		$domain = $site->getProperties()->get(Site::DOMAIN_PROPERTY_KEY)?->getValue();
		$domainUrl = is_string($domain) ? 'https://'.$domain : '';
		$parsedDomain = '' !== $domainUrl ? parse_url($domainUrl) : false;
		if (
			!is_string($domain) ||
			1 !== preg_match('/^(?:\[[0-9a-f:]+\]|[a-z0-9.-]+)(?::[0-9]{1,5})?$/iD', $domain) ||
			false === $parsedDomain ||
			false === filter_var($domainUrl, FILTER_VALIDATE_URL)
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

	private function customCssHtml(CustomCssCollector $customCss, bool $editorMode): string
	{
		$customCssHtml = $editorMode ? $customCss->stylesheets() : $customCss->stylesheet();
		if (!$editorMode && '' !== $customCssHtml) {
			$customCssHtml = '<style id="iikiti-custom-css">'.$customCssHtml.'</style>';
		}

		return $customCssHtml;
	}

	/**
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function dynamicBlocksFor(?DbObject $object, bool $draft): array
	{
		if (null === $object) {
			return [];
		}

		$key = $draft ? 'dynamic_blocks_draft' : 'dynamic_blocks';
		$value = $object->getProperties()->get($key)?->getValue();

		return is_array($value) ? $value : [];
	}

	private function defaultTemplate(): Template
	{
		$template = new Template();
		$prop = (new \ReflectionClass(DbObject::class))->getProperty('properties');
		$prop->setAccessible(true);
		$prop->setValue($template, new ArrayCollection());
		$template->setLayout(Template::DEFAULT_LAYOUT);
		// Only `main` is a fixed region; header, footer, sidebars and dialogs are shells.
		$template->setRegions([
			['id' => 'main', 'name' => 'Main content', 'role' => 'main', 'allowed_types' => []],
		]);

		return $template;
	}

	/**
	 * @param array{defaults:array<string,mixed>,overrides:array<string,mixed>,effective:array<string,mixed>}|null $resolvedOptimization
	 *
	 * @return array{type:string,id:int,label:string,values:array<string,mixed>,version:int,hasDraft:bool,options:list<array<string,mixed>>,seoPreviewDefaults:array<string,mixed>}
	 */
	private function settingsContext(
		?DbObject $object,
		Template $template,
		?User $user,
		bool $home,
		?Site $site,
		Request $request,
		?array $resolvedOptimization = null,
	): array {
		$persistedTemplate = null !== $template->getId() ? $template : null;
		$canEditSiteHome = false;
		if ($home && null === $object && null !== $site && null !== $user) {
			if (isset($this->homepageSeoAccessPolicy)) {
				$canEditSiteHome = $this->homepageSeoAccessPolicy->canEdit($user, $site, $persistedTemplate);
			} elseif (isset($this->workflowAccessPolicy)) {
				$canEditSiteHome = in_array(HomepageSeoAccessPolicy::CONTENT_OPTIMIZATION_PLUGIN_SLUG, $site->getEnabledPlugins(), true)
					&& $this->workflowAccessPolicy->canManageSite($user, $site)
					&& $this->configProvider->canAccess($user, 'Template', 'publish', $persistedTemplate);
			}
		}
		$target = $canEditSiteHome ?
			$site :
			($object instanceof Page && $this->configProvider->canAccess($user, 'Page', 'write', $object) ? $object : $template);
		$targetType = $canEditSiteHome ? 'site_home' : ($target instanceof Page ? 'page' : 'template');
		$state = $this->settingsManager->state($target);
		$values = 'site_home' === $targetType ? $this->seoPageSettings($state['values']) : $state['values'];
		$options = [];
		if ($canEditSiteHome) {
			$templateState = $this->settingsManager->state($template);
			$options = [
				[
					'type' => 'site_home',
					'id' => (int) ($site->getId() ?? 0),
					'label' => 'Homepage SEO Settings',
					'values' => $values,
					'version' => $state['version'],
					'hasDraft' => $state['hasDraft'],
				],
				[
					'type' => 'template',
					'id' => (int) ($template->getId() ?? 0),
					'label' => 'Shared Template Settings',
					'values' => $templateState['values'],
					'version' => $templateState['version'],
					'hasDraft' => $templateState['hasDraft'],
				],
			];
		}
		$seoPreviewDefaults = [];
		if (
			$site instanceof Site
			&& isset($this->contentOptimizationSettings)
			&& in_array(HomepageSeoAccessPolicy::CONTENT_OPTIMIZATION_PLUGIN_SLUG, $site->getEnabledPlugins(), true)
			&& in_array($targetType, ['page', 'site_home'], true)
		) {
			$effective = (null !== $resolvedOptimization && [] !== $resolvedOptimization['effective']
				? $resolvedOptimization
				: $this->resolveOptimizationSettings($site, false))['effective'];
			$seo = is_array($effective['seo'] ?? null) ? $effective['seo'] : [];
			$siteName = $this->siteName($site, $request);
			$baseTitle = $this->pageTitle($template, $request, $siteName);
			$seoPreviewDefaults = [
				'pageTitle' => $object instanceof Page ? $this->pageTitle($object, $request, $baseTitle) : $baseTitle,
				'siteName' => $siteName,
				'titleTemplate' => $seo['titleTemplate'] ?? '{pageTitle} | {siteName}',
				'description' => $seo['defaultDescription'] ?? '',
				'image' => $seo['defaultSocialImage'] ?? '',
			];
		}

		return [
			'type' => $targetType,
			'id' => (int) ($target->getId() ?? 0),
			'label' => 'page' === $targetType ? 'Page Settings' : ('site_home' === $targetType ? 'Homepage SEO Settings' : 'Template Settings'),
			'values' => $values,
			'version' => $state['version'],
			'hasDraft' => $state['hasDraft'],
			'options' => $options,
			'seoPreviewDefaults' => $seoPreviewDefaults,
		];
	}

	/**
	 * @param array<string,mixed> $settings
	 *
	 * @return array<string,mixed>
	 */
	private function seoPageSettings(array $settings): array
	{
		return array_filter(
			$settings,
			static fn ($value, string $key): bool => str_starts_with($key, 'iikiti_content_optimization_'),
			ARRAY_FILTER_USE_BOTH,
		);
	}

	/** @param array<string,mixed> $settings */
	private function appendCustomCss(CustomCssCollector $collector, array $settings, ?string $scopeClass, ?string $styleId = null): void
	{
		$source = $settings['customCss'] ?? null;
		if (null === $scopeClass || !is_string($source) || '' === trim($source)) {
			return;
		}

		try {
			$collector->append($this->customCssCompiler->compileScoped($source, 'body.'.$scopeClass), $styleId);
		} catch (\InvalidArgumentException) {
			// Invalid custom CSS remains editable but is omitted from the public stylesheet.
		}
	}

	private function scopeClass(string $type, ?int $id): ?string
	{
		return null !== $id && $id > 0 ? 'iikiti-'.$type.'-id--'.$id : null;
	}

	private function customCssStyleId(string $type, ?int $id): ?string
	{
		return null !== $id && $id > 0 ? 'iikiti-custom-css-preview-'.$type.'-'.$id : null;
	}
}
