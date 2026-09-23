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
		$authorized = $request->query->has('edit') && $user instanceof User;
		$config = $authorized
			? $this->configProvider->build(
				contextId: (int) ($template->getId() ?? 0),
				contextType: 'template',
				user: $user,
			)
			: null;
		$editorMode = null !== $config;
		$regionTrees = ($editorMode && $template->getBlocksDraft() ? $template->getBlocksDraft() : ($template->getBlocks() ?: []));
		$dynamicBlocks = $this->dynamicBlocksFor($object, $editorMode);

		$renderContext = new BlockRenderContext(
			editorMode: $editorMode,
			site: $site,
			object: $object,
			request: $request,
			dynamicBlocks: $dynamicBlocks,
		);

		$pageHtml = $this->templateRenderer->render($template, $renderContext, $regionTrees);

		return new Response($this->twig->render('base/layout.twig', [
			'doc' => ['title' => $template->getTitle() ?? 'iikiti'],
			'iikiti_page_body' => $pageHtml,
			'iikiti_editor_mode' => $editorMode,
			'iikiti_config' => $config,
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
		$template->setRegions([
			['id' => 'header', 'name' => 'Header', 'role' => 'header', 'allowed_types' => []],
			['id' => 'main', 'name' => 'Main content', 'role' => 'main', 'allowed_types' => []],
			['id' => 'aside-left', 'name' => 'Left sidebar', 'role' => 'sidebar', 'allowed_types' => []],
			['id' => 'aside-right', 'name' => 'Right sidebar', 'role' => 'sidebar', 'allowed_types' => []],
			['id' => 'footer', 'name' => 'Footer', 'role' => 'footer', 'allowed_types' => []],
		]);

		return $template;
	}
}
