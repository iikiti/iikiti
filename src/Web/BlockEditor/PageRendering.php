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
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use iikiti\CMS\Web\Fragment\PageFragmentResult;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\CustomCssCollector;
use iikiti\CMS\Web\Template\TemplateRenderer;
use iikiti\CMS\Web\Template\TemplateResolutionContext;
use iikiti\CMS\Web\Template\TemplateResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
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
	/**
	 * @param iterable<PageRenderContributorInterface>          $renderContributors
	 * @param iterable<EditorSettingsContextContributorInterface> $settingsContributors
	 */
	public function __construct(
		private readonly \iikiti\CMS\Web\Icon\IconFontAssets $iconFontAssets,
		private readonly TemplateResolver $resolver,
		private readonly TemplateRenderer $templateRenderer,
		private readonly FrontendConfigProvider $configProvider,
		private readonly EditorSettingsManager $settingsManager,
		private readonly CustomCssCompilerInterface $customCssCompiler,
		private readonly Security $security,
		private readonly Environment $twig,
		private readonly InlineAssetMinificationOptionsResolver $inlineAssetOptions,
		#[AutowireIterator('iikiti.cms.page_render_contributor')]
		private readonly iterable $renderContributors = [],
		#[AutowireIterator('iikiti.cms.editor_settings_contributor')]
		private readonly iterable $settingsContributors = [],
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
			'template[data-iikiti-fragment="page"]',
			resolutionContext: $state['resolution_context'],
		);

		$fontPaths = $this->iconFontAssets->shouldLink($state['can_edit'])
			? $this->iconFontAssets->getStylesheetPaths()
			: [];
		$iconFont = [] !== $fontPaths
			? $this->twig->render('base/_icon_font_link.twig', ['iikiti_icon_font_paths' => $fontPaths])
			: '';
		$composer->insertIntoPlaceholder('template[data-iikiti-fragment="icon-font"]', $iconFont);

		$composer->insertIntoPlaceholder('template[data-iikiti-fragment="custom-css"]', $this->customCssHtml($customCss, $state['editor_mode']));

		return new DomHtmlResponse($composer->indexedDocument());
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
		$fontPaths = $this->iconFontAssets->shouldLink($state['can_edit'])
			? $this->iconFontAssets->getStylesheetPaths()
			: [];
		// Render the layout skeleton directly with Twig instead of parsing and
		// re-serializing a temporary HTMLDocument on the API side.
		$html = $this->baseLayout($request, $state, [
			'iikiti_use_fragment_composer' => false,
			'iikiti_custom_css_placeholder' => true,
			'iikiti_page_body' => $manifest['html'],
			'iikiti_icon_font' => [] !== $fontPaths,
			'iikiti_icon_font_paths' => $fontPaths,
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
		$settingsContext = !$forFragment && $canEdit
			? $this->settingsContext($object, $template, $user, $home, $site, $request)
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
			$this->settingsManager->publishedSettings($site) :
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
		$config = $state['config'];
		$context = [
			'doc' => ['title' => $state['template']->getTitle() ?? 'iikiti'],
			'iikiti_page_locale' => $this->publicLocale($request),
			'iikiti_page_body' => '',
			'iikiti_editor_noindex' => $state['editor_mode'],
			'iikiti_use_fragment_composer' => true,
			'iikiti_editor_mode' => $state['editor_mode'],
			'iikiti_can_edit' => $state['can_edit'],
			'iikiti_config' => $config,
			'iikiti_config_json' => null !== $config && [] !== $config ? json_encode(
				$config,
				JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
			) : null,
			'iikiti_icon_font' => false,
			'iikiti_icon_font_paths' => [],
			'iikiti_scope_classes' => $state['scope_classes'],
			'iikiti_custom_css' => '',
		];

		$headHtml = [];
		$bodyClasses = [];
		$bodyBefore = [];
		$bodyAfter = [];
		$jsonBlocks = [];
		$extraTemplateVars = [];
		$contributorState = $state;
		foreach ($this->renderContributors as $contributor) {
			$contribution = $contributor->contribute(
				$request,
				$state['site'],
				$state['object'],
				$state['template'],
				(bool) $state['editor_mode'],
				$contributorState,
			);
			if (null === $contribution) {
				continue;
			}
			if (null !== $contribution->title && '' !== trim($contribution->title)) {
				$context['doc']['title'] = $contribution->title;
			}
			array_push($headHtml, ...$contribution->headHtml);
			array_push($bodyClasses, ...$contribution->bodyClasses);
			array_push($bodyBefore, ...$contribution->bodyBefore);
			array_push($bodyAfter, ...$contribution->bodyAfter);
			foreach ($contribution->jsonBlocks as $blockId => $payload) {
				if (is_string($payload)) {
					$jsonBlocks[$blockId] = $payload;
				} else {
					$jsonBlocks[$blockId] = json_encode(
						$payload,
						JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
					);
				}
			}
			foreach ($contribution->templateVars as $key => $value) {
				$extraTemplateVars[$key] = $value;
				$contributorState[$key] = $value;
			}
			$contributorState['body_before'] = $bodyBefore;
		}

		$context['iikiti_head_html'] = $headHtml;
		$context['iikiti_body_classes'] = array_values(array_unique($bodyClasses));
		$context['iikiti_body_before'] = $bodyBefore;
		$context['iikiti_body_after'] = $bodyAfter;
		$context['iikiti_json_blocks'] = $jsonBlocks;

		return $this->twig->render('base/layout.twig', array_replace($context, $extraTemplateVars, $overrides));
	}

	private function publicLocale(Request $request): string
	{
		try {
			return \iikiti\CMS\Value\LocaleTag::tryFromString(str_replace('_', '-', $request->getLocale()))?->getTag() ?? 'en';
		} catch (\InvalidArgumentException) {
			return 'en';
		}
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
	 * @return array{type:string,id:int,label:string,values:array<string,mixed>,version:int,hasDraft:bool,options:list<array<string,mixed>>,seoPreviewDefaults:array<string,mixed>}
	 */
	private function settingsContext(
		?DbObject $object,
		Template $template,
		?User $user,
		bool $home,
		?Site $site,
		Request $request,
	): array {
		$target = $object instanceof Page && $this->configProvider->canAccess($user, 'Page', 'write', $object) ? $object : $template;
		$targetType = $target instanceof Page ? 'page' : 'template';
		$state = $this->settingsManager->state($target);

		$context = [
			'type' => $targetType,
			'id' => (int) ($target->getId() ?? 0),
			'label' => 'page' === $targetType ? 'Page Settings' : 'Template Settings',
			'values' => $state['values'],
			'version' => $state['version'],
			'hasDraft' => $state['hasDraft'],
			'options' => [],
			'seoPreviewDefaults' => [],
		];

		foreach ($this->settingsContributors as $contributor) {
			$context = $contributor->contributeSettingsContext(
				$context,
				$object,
				$template,
				$user,
				$home,
				$site,
				$request,
				['target' => $target, 'state' => $state],
			);
		}

		return $context;
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
