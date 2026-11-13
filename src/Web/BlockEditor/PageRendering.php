<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use Doctrine\Common\Collections\ArrayCollection;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\CustomCssCollector;
use iikiti\CMS\Web\Fragment\PageFragmentResult;
use iikiti\CMS\Web\Template\TemplateRenderer;
use iikiti\CMS\Web\Template\TemplateResolutionContext;
use iikiti\CMS\Web\Template\TemplateResolver;
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
	) {
	}

	public function render(Request $request, ?DbObject $object = null, bool $home = false): Response
	{
		$state = $this->prepareRenderState($request, $object, $home);
		$template = $state['template'];
		$renderContext = $state['render_context'];
		$customCss = $state['custom_css'];
		$layout = $this->baseLayout($state);
		$composer = new HtmlFragmentComposer($layout);
		$this->templateRenderer->renderIntoDocument(
			$template,
			$renderContext,
			$state['region_trees'],
			$composer,
			'[data-iikiti-fragment="page"]',
			resolutionContext: $state['resolution_context'],
		);

		$iconFont = $this->iconFontAssets->shouldLink($state['can_edit'])
			? $this->twig->render('base/_icon_font_link.twig')
			: '';
		$composer->insertIntoPlaceholder('[data-iikiti-fragment="icon-font"]', $iconFont);

		$composer->insertIntoPlaceholder('[data-iikiti-fragment="custom-css"]', $this->customCssHtml($customCss, $state['editor_mode']));

		return new DomHtmlResponse($composer->document());
	}

	/**
	 * Returns a server-rendered layout skeleton and fixed API fragment descriptors.
	 *
	 * @return array{html:string,styles:list<array{id:?string,css:string}>,editor_mode:bool,revision:string,fragments:list<array{kind:string,id:string,role:string,slot:string}>}
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
		$composer = new HtmlFragmentComposer($this->baseLayout($state));
		$composer->insertIntoPlaceholder('[data-iikiti-fragment="page"]', $manifest['html']);
		$iconFont = $this->iconFontAssets->shouldLink($state['can_edit'])
			? $this->twig->render('base/_icon_font_link.twig')
			: '';
		$composer->insertIntoPlaceholder('[data-iikiti-fragment="icon-font"]', $iconFont);

		return [
			'html' => $composer->render(),
			'styles' => $state['custom_css']->fragments($state['editor_mode']),
			'editor_mode' => $state['editor_mode'],
			'revision' => $manifest['revision'],
			'fragments' => $manifest['fragments'],
		];
	}

	/**
	 * @return PageFragmentResult|null
	 */
	public function renderFragment(Request $request, ?DbObject $object, bool $home, string $kind, string $id, ?string $expectedRevision = null): ?PageFragmentResult
	{
		$state = $this->prepareRenderState($request, $object, $home, includePageStyles: false);

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
	 *   scope_classes:list<string>
	 * }
	 */
	private function prepareRenderState(Request $request, ?DbObject $object, bool $home, bool $includePageStyles = true): array
	{
		$site = SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null;
		$resolutionContext = new TemplateResolutionContext(
			site: $site,
			object: $object,
			objectType: null === $object ? null : $object::class,
			home: $home,
		);
		$template = $this->resolver->resolve($resolutionContext) ?? $this->defaultTemplate();
		$user = $this->security->getUser();
		$canEdit = $user instanceof User && $this->configProvider->canEdit($user);
		$editorMode = $canEdit && $request->query->has('edit');
		$settingsContext = $canEdit ? $this->settingsContext($object, $template, $user) : null;
		$config = $canEdit
			? $this->configProvider->build(
				contextId: (int) ($template->getId() ?? 0),
				contextType: 'template',
				user: $user,
				// The current draft version — the editor echoes it back as
				// `If-Match` on save; a missing version would 409 every save.
				version: $template->getDraftVersion(),
				settingsContext: $settingsContext ?? [],
			)
			: null;
		$regionTrees = ($editorMode && $template->getBlocksDraft() ? $template->getBlocksDraft() : ($template->getBlocks() ?: []));
		$customCss = new CustomCssCollector();
		if ($includePageStyles) {
			$templateSettings = $editorMode && 'template' === ($settingsContext['type'] ?? null)
				? $settingsContext['values']
				: $this->settingsManager->publishedSettings($template);
			$this->appendCustomCss(
				$customCss,
				$templateSettings,
				$this->scopeClass('template', $template->getId()),
				$editorMode ? $this->customCssStyleId('template', $template->getId()) : null,
			);
			if ($object instanceof Page) {
				$pageSettings = $editorMode && 'page' === ($settingsContext['type'] ?? null)
					? $settingsContext['values']
					: $this->settingsManager->publishedSettings($object);
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
		$this->twig->addGlobal('iikiti_config', $config);

		return [
			'template' => $template,
			'resolution_context' => $resolutionContext,
			'render_context' => $renderContext,
			'region_trees' => $regionTrees,
			'custom_css' => $customCss,
			'editor_mode' => $editorMode,
			'can_edit' => $canEdit,
			'config' => $config,
			'scope_classes' => array_values(array_filter([
				$this->scopeClass('template', $template->getId()),
				$object instanceof Page ? $this->scopeClass('page', $object->getId()) : null,
			])),
		];
	}

	/** @param array<string,mixed> $state */
	private function baseLayout(array $state): string
	{
		return $this->twig->render('base/layout.twig', [
			'doc' => ['title' => $state['template']->getTitle() ?? 'iikiti'],
			'iikiti_page_body' => '',
			'iikiti_use_fragment_composer' => true,
			'iikiti_editor_mode' => $state['editor_mode'],
			'iikiti_can_edit' => $state['can_edit'],
			'iikiti_config' => $state['config'],
			'iikiti_icon_font' => false,
			'iikiti_scope_classes' => $state['scope_classes'],
			'iikiti_custom_css' => '',
		]);
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

	/** @return array{type:string,id:int,label:string,values:array<string,mixed>,version:int,hasDraft:bool} */
	private function settingsContext(?DbObject $object, Template $template, ?User $user): array
	{
		$target = $object instanceof Page && $this->configProvider->canAccess($user, 'Page', 'write', $object)
			? $object
			: $template;
		$targetType = $target instanceof Page ? 'page' : 'template';
		$state = $this->settingsManager->state($target);

		return [
			'type' => $targetType,
			'id' => (int) ($target->getId() ?? 0),
			'label' => 'page' === $targetType ? 'Page Settings' : 'Template Settings',
			'values' => $state['values'],
			'version' => $state['version'],
			'hasDraft' => $state['hasDraft'],
		];
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
