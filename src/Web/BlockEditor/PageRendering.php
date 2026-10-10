<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use Doctrine\Common\Collections\ArrayCollection;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
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
		$config = $canEdit
			? $this->configProvider->build(
				contextId: (int) ($template->getId() ?? 0),
				contextType: 'template',
				user: $user,
				// The current draft version — the editor echoes it back as
				// `If-Match` on save; a missing version would 409 every save.
				version: $template->getDraftVersion(),
			)
			: null;
		$regionTrees = ($editorMode && $template->getBlocksDraft() ? $template->getBlocksDraft() : ($template->getBlocks() ?: []));
		$dynamicBlocks = $this->dynamicBlocksFor($object, $editorMode);

		$renderContext = new BlockRenderContext(
			editorMode: $editorMode,
			canEdit: $canEdit,
			site: $site,
			object: $object,
			request: $request,
			dynamicBlocks: $dynamicBlocks,
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
}
