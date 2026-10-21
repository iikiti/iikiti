<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use Doctrine\Common\Collections\ArrayCollection;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\CustomCssCollector;
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
		$site = SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null;
		$context = new TemplateResolutionContext(
			site: $site,
			object: $object,
			objectType: null === $object ? null : $object::class,
			home: $home,
		);

		$template = $this->resolver->resolve($context) ?? $this->defaultTemplate();
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
		$dynamicBlocks = $this->dynamicBlocksFor($object, $editorMode);
		$customCss = new CustomCssCollector();
		$templateSettings = $editorMode && 'template' === ($settingsContext['type'] ?? null)
			? $settingsContext['values']
			: $this->settingsManager->publishedSettings($template);
		$this->appendCustomCss($customCss, $templateSettings, $this->scopeClass('template', $template->getId()));
		if ($object instanceof Page) {
			$pageSettings = $editorMode && 'page' === ($settingsContext['type'] ?? null)
				? $settingsContext['values']
				: $this->settingsManager->publishedSettings($object);
			$this->appendCustomCss($customCss, $pageSettings, $this->scopeClass('page', $object->getId()));
		}

		$renderContext = new BlockRenderContext(
			editorMode: $editorMode,
			canEdit: $canEdit,
			site: $site,
			object: $object,
			request: $request,
			dynamicBlocks: $dynamicBlocks,
			customCssCollector: $customCss,
		);

		$resolutionContext = new TemplateResolutionContext(
			site: $site,
			object: $object,
			objectType: null === $object ? null : $object::class,
			home: $home,
		);
		$pageHtml = $this->templateRenderer->render(
			$template,
			$renderContext,
			$regionTrees,
			resolutionContext: $resolutionContext,
		);

		$this->twig->addGlobal('iikiti_can_edit', $canEdit);
		$this->twig->addGlobal('iikiti_editor_mode', $editorMode);
		$this->twig->addGlobal('iikiti_config', $config);

		return new Response($this->twig->render('base/layout.twig', [
			'doc' => ['title' => $template->getTitle() ?? 'iikiti'],
			'iikiti_page_body' => $pageHtml,
			'iikiti_editor_mode' => $editorMode,
			'iikiti_can_edit' => $canEdit,
			'iikiti_config' => $config,
			// Editor/admin always load the font; public pages only when an icon used it and it is enabled.
			'iikiti_icon_font' => $this->iconFontAssets->shouldLink($canEdit || $editorMode),
			'iikiti_scope_classes' => array_values(array_filter([
				$this->scopeClass('template', $template->getId()),
				$object instanceof Page ? $this->scopeClass('page', $object->getId()) : null,
			])),
			'iikiti_custom_css' => $customCss->stylesheet(),
		]));
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
	private function appendCustomCss(CustomCssCollector $collector, array $settings, ?string $scopeClass): void
	{
		$source = $settings['customCss'] ?? null;
		if (null === $scopeClass || !is_string($source) || '' === trim($source)) {
			return;
		}

		try {
			$collector->append($this->customCssCompiler->compileScoped($source, 'body.'.$scopeClass));
		} catch (\InvalidArgumentException) {
			// Invalid custom CSS remains editable but is omitted from the public stylesheet.
		}
	}

	private function scopeClass(string $type, ?int $id): ?string
	{
		return null !== $id && $id > 0 ? 'iikiti-'.$type.'-id--'.$id : null;
	}
}
