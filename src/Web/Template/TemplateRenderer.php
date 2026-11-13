<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template;

use Dom\DocumentFragment;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Profiler\AsyncResponseDataCollector;
use iikiti\CMS\Profiler\ProfilerQueryDiagnostics;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use iikiti\CMS\Web\BlockEditor\Embed\EmbedResolver;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderer;
use iikiti\CMS\Web\BlockEditor\Render\RenderCacheTagCollector;
use iikiti\CMS\Web\BlockEditor\Render\RenderedFragmentCache;
use iikiti\CMS\Web\BlockEditor\RenderFragmentLimits;
use iikiti\CMS\Web\BlockEditor\Render\WidgetFragmentPlan;
use iikiti\CMS\Web\Fragment\FragmentContext;
use iikiti\CMS\Web\Fragment\FragmentPlanCollector;
use iikiti\CMS\Web\Fragment\FragmentResult;
use iikiti\CMS\Web\Fragment\PageFragmentResult;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * Renders a resolved {@see Template}: resolves the layout Twig file, renders each
 * region's block tree via {@see BlockRenderer}, and enforces the requirement that
 * every layout declares at least one `main` region (otherwise an error message is
 * shown instead of silently rendering empty content).
 */
final class TemplateRenderer
{
	public const REGION_ROLE_MAIN = 'main';

	public function __construct(
		private readonly BlockRenderer $blockRenderer,
		private readonly Environment $twig,
		private readonly ?ShellResolver $shellResolver = null,
		private readonly ?ShellProvider $shellProvider = null,
		private readonly ?FiberTaskRunner $fiberTaskRunner = null,
		private readonly ?EmbedResolver $embedResolver = null,
		private readonly ?AsyncResponseDataCollector $profiler = null,
		private readonly ?RenderedFragmentCache $renderedFragmentCache = null,
	) {
	}

	/**
	 * Renders the template's layout with the given trees (published by default;
	 * pass draft trees when editing).
	 *
	 * @param array<string,list<array<string,mixed>>> $regionTrees per-region block trees
	 * @param array<string,mixed>                     $settings
	 * @param TemplateResolutionContext|null          $resolutionContext needed to pick shells for this request
	 */
	public function render(
		Template $template,
		BlockRenderContext $context,
		array $regionTrees,
		array $settings = [],
		?TemplateResolutionContext $resolutionContext = null,
	): string {
		$regions = $template->getRegions();
		if ([] !== $regions && !$this->hasMainRegion($regions)) {
			return $this->renderNoMainRegion($context);
		}

		$vars = [
			'iikiti_editor_mode' => $context->editorMode,
			'iikiti_can_edit' => $context->canEdit,
			'iikiti_regions' => $regions,
			'iikiti_settings' => $settings ?: $template->getSettings(),
			'iikiti_site' => $context->site,
			'_iikiti_fragment_context' => new FragmentContext($context->site, $context->request?->getLocale() ?? 'und'),
			'_iikiti_fragment_styles' => $context->customCssCollector,
		];

		$shells = $this->resolveShells($resolutionContext);
		if (null !== $this->fiberTaskRunner && null !== $this->embedResolver) {
			$context = $context->withPrefetchedEmbeds($this->embedResolver->prefetchOembeds(
				$this->embedUrls($regionTrees, $context, $shells),
				$this->fiberTaskRunner,
			));
		}

		foreach ($regions as $region) {
			$regionId = (string) ($region['id'] ?? '');
			if ('' === $regionId) {
				continue;
			}
			$var = $this->regionVar($regionId);
			$tree = $regionTrees[$regionId] ?? null;
			$vars[$var] = $this->blockRenderer->renderRegionTree($tree, $context);
		}

		$vars['iikiti_shells'] = $this->renderShells($context, $shells);

		$this->twig->addGlobal('iikiti_can_edit', $context->canEdit);
		$this->twig->addGlobal('iikiti_editor_mode', $context->editorMode);

		try {
			return $this->twig->render($template->getLayout(), $vars);
		} catch (LoaderError|RuntimeError|SyntaxError $e) {
			return $this->renderError($e->getMessage());
		}
	}

	/**
	 * Renders the layout skeleton into the main DOM, then fills component slots
	 * while eligible outbound HTTP requests are outstanding.
	 *
	 * @param array<string,list<array<string,mixed>>> $regionTrees
	 * @param array<string,mixed> $settings
	 */
	public function renderIntoDocument(
		Template $template,
		BlockRenderContext $context,
		array $regionTrees,
		HtmlFragmentComposer $composer,
		string $pagePlaceholderSelector,
		array $settings = [],
		?TemplateResolutionContext $resolutionContext = null,
	): void {
		$regions = $template->getRegions();
		if ([] !== $regions && !$this->hasMainRegion($regions)) {
			$composer->insertIntoPlaceholder($pagePlaceholderSelector, $this->renderNoMainRegion($context));

			return;
		}

		$this->twig->addGlobal('iikiti_can_edit', $context->canEdit);
		$this->twig->addGlobal('iikiti_editor_mode', $context->editorMode);
		$deferredShellEmbeds = [];
		$renderLayoutAndFragments = function (array $regionEmbeds) use (
			$template,
			$context,
			$regions,
			$regionTrees,
			$composer,
			$pagePlaceholderSelector,
			$settings,
			$resolutionContext,
			&$deferredShellEmbeds,
		): void {
			$renderContext = $context->withPrefetchedEmbeds($regionEmbeds);
			$orderedRegions = $regions;
			$regionPriority = ['main' => 0, 'header' => 2];
			usort($orderedRegions, static fn (array $left, array $right): int =>
				($regionPriority[$left['role'] ?? ''] ?? 1) <=> ($regionPriority[$right['role'] ?? ''] ?? 1));
			/** @var array<string,DocumentFragment> $renderedRegions */
			$renderedRegions = [];
			foreach ($orderedRegions as $region) {
				$regionId = (string) ($region['id'] ?? '');
				if ('' !== $regionId) {
					$html = $this->blockRenderer->renderRegionTree($regionTrees[$regionId] ?? null, $renderContext);
					$renderedRegions[$regionId] = $composer->createFragment($html);
				}
			}

			$shells = $this->resolveShells($resolutionContext);
			$regionSlots = [];
			$vars = [
				'iikiti_editor_mode' => $context->editorMode,
				'iikiti_can_edit' => $context->canEdit,
				'iikiti_regions' => $regions,
				'iikiti_settings' => $settings ?: $template->getSettings(),
				'iikiti_site' => $context->site,
				'_iikiti_fragment_context' => new FragmentContext($context->site, $context->request?->getLocale() ?? 'und'),
				'_iikiti_fragment_styles' => $context->customCssCollector,
				'iikiti_shells' => [],
			];
			foreach ($regions as $region) {
				$regionId = (string) ($region['id'] ?? '');
				if ('' === $regionId) {
					continue;
				}

				$token = bin2hex(random_bytes(16));
				$regionSlots[$regionId] = $token;
				$vars[$this->regionVar($regionId)] = sprintf('<template data-iikiti-region-slot="%s"></template>', $token);
			}

			$shellSlots = [];
			foreach ($shells as $role => $roleShells) {
				foreach ($roleShells as $shell) {
					$token = bin2hex(random_bytes(16));
					$shellSlots[$role][] = ['token' => $token, 'shell' => $shell];
					$vars['iikiti_shells'][$role][] = sprintf('<template data-iikiti-shell-slot="%s"></template>', $token);
				}
			}

			try {
				$layout = $this->twig->render($template->getLayout(), $vars);
			} catch (LoaderError|RuntimeError|SyntaxError $exception) {
				$composer->insertIntoPlaceholder($pagePlaceholderSelector, $this->renderError($exception->getMessage()));

				return;
			}
			$composer->insertIntoPlaceholder($pagePlaceholderSelector, $layout);
			$this->profiler?->recordComposition('html-dom', count($regionSlots) + array_sum(array_map('count', $shellSlots)));

			foreach ($renderedRegions as $regionId => $html) {
				if (!isset($regionSlots[$regionId])) {
					continue;
				}
				$selector = sprintf('template[data-iikiti-region-slot="%s"]', $regionSlots[$regionId]);
				if ($composer->hasPlaceholder($selector)) {
					$composer->insertFragmentIntoPlaceholder($selector, $html);
				}
			}

			$shellUrls = array_values(array_diff($this->embedUrls([], $context, $shells), array_keys($regionEmbeds)));
			$renderShells = function (array $shellEmbeds) use ($shellSlots, $regionEmbeds, $renderContext, $composer): void {
				$shellContext = $renderContext->withPrefetchedEmbeds($regionEmbeds + $shellEmbeds);
				$roles = array_keys($shellSlots);
				$rolePriority = ['aside' => 0, 'sidebar' => 0, 'footer' => 1, 'dialog' => 2, 'header' => 3];
				usort($roles, static fn (string $left, string $right): int => ($rolePriority[$left] ?? 1) <=> ($rolePriority[$right] ?? 1));
				foreach ($roles as $role) {
					foreach ($shellSlots[$role] as $entry) {
						$html = $this->blockRenderer->renderRegionTree(
							is_array($entry['shell']['blocks'] ?? null) ? $entry['shell']['blocks'] : null,
							$shellContext,
						);
					$selector = sprintf('template[data-iikiti-shell-slot="%s"]', $entry['token']);
					if ($composer->hasPlaceholder($selector)) {
						$composer->insertFragmentIntoPlaceholder($selector, $composer->createFragment($html));
						}
					}
				}
			};

			if ([] !== $shellUrls && null !== $this->fiberTaskRunner && null !== $this->embedResolver) {
				$deferredShellEmbeds = $this->embedResolver->renderWhilePrefetchingOembeds(
					$shellUrls,
					$this->fiberTaskRunner,
					$renderShells,
				);
			} else {
				$renderShells([]);
			}
		};

		if (null !== $this->fiberTaskRunner && null !== $this->embedResolver) {
			$deferredRegionEmbeds = $this->embedResolver->renderWhilePrefetchingOembeds(
				$this->embedUrls($regionTrees, $context, []),
				$this->fiberTaskRunner,
				$renderLayoutAndFragments,
			);
			$composer->replaceDeferredEmbeds($deferredRegionEmbeds + $deferredShellEmbeds);
		} else {
			$renderLayoutAndFragments($context->prefetchedEmbeds);
		}
	}

	/**
	 * Renders a server-authored layout skeleton and describes its fixed fragments.
	 *
	 * @param array<string,list<array<string,mixed>>> $regionTrees
	 * @return array{html:string,revision:string,fragments:list<array<string,mixed>>}
	 */
	public function renderManifest(
		Template $template,
		BlockRenderContext $context,
		array $regionTrees,
		?TemplateResolutionContext $resolutionContext = null,
	): array {
		$regions = $template->getRegions();
		$shells = $this->resolveShells($resolutionContext);
		$revision = $this->renderRevision($template, $context, $regionTrees, $shells);
		if ([] !== $regions && !$this->hasMainRegion($regions)) {
			return ['html' => $this->renderNoMainRegion($context), 'revision' => $revision, 'fragments' => []];
		}

		$fragments = [];
		$regionSlots = [];
		$orderedRegions = $regions;
		$regionPriority = ['main' => 0, 'header' => 2];
		usort($orderedRegions, static fn (array $left, array $right): int =>
			($regionPriority[$left['role'] ?? ''] ?? 1) <=> ($regionPriority[$right['role'] ?? ''] ?? 1));
		foreach ($orderedRegions as $region) {
			$regionId = (string) ($region['id'] ?? '');
			if ('' === $regionId) {
				continue;
			}

			$slot = bin2hex(random_bytes(16));
			$regionSlots[$regionId] = $slot;
			$fragments[] = ['kind' => 'region', 'id' => $regionId, 'role' => (string) ($region['role'] ?? ''), 'slot' => $slot];
		}

		$shellSlots = [];
		$roles = array_keys($shells);
		$rolePriority = ['aside' => 0, 'sidebar' => 0, 'footer' => 1, 'dialog' => 2, 'header' => 3];
		usort($roles, static fn (string $left, string $right): int => ($rolePriority[$left] ?? 1) <=> ($rolePriority[$right] ?? 1));
		foreach ($roles as $role) {
			$roleShells = $shells[$role];
			foreach ($roleShells as $shell) {
				$shellId = (string) ($shell['id'] ?? '');
				if ('' === $shellId) {
					continue;
				}

				$slot = bin2hex(random_bytes(16));
				$shellSlots[$role][] = ['slot' => $slot, 'shell' => $shell];
				$fragments[] = ['kind' => 'shell', 'id' => $shellId, 'role' => $role, 'slot' => $slot];
			}
		}

		$vars = [
			'iikiti_editor_mode' => $context->editorMode,
			'iikiti_can_edit' => $context->canEdit,
			'iikiti_regions' => $regions,
			'iikiti_settings' => $template->getSettings(),
			'iikiti_site' => $context->site,
			'iikiti_shells' => [],
			'_iikiti_fragment_context' => new FragmentContext($context->site, $context->request?->getLocale() ?? 'und'),
			'_iikiti_fragment_styles' => $context->customCssCollector,
		];
		$fragmentPlan = new FragmentPlanCollector();
		$vars['_iikiti_fragment_plan'] = $fragmentPlan;
		foreach ($regionSlots as $regionId => $slot) {
			$vars[$this->regionVar($regionId)] = sprintf('<template data-iikiti-region-slot="%s"></template>', $slot);
		}
		foreach ($shellSlots as $role => $roleShells) {
			foreach ($roleShells as $entry) {
				$vars['iikiti_shells'][$role][] = sprintf('<template data-iikiti-shell-slot="%s"></template>', $entry['slot']);
			}
		}

		try {
			$html = $this->twig->render($template->getLayout(), $vars);
			$pluginFragments = $fragmentPlan->serverDescriptors();
		} catch (LoaderError|RuntimeError|SyntaxError|\InvalidArgumentException $exception) {
			$html = $this->renderError($exception->getMessage());
			$fragments = [];
			$pluginFragments = [];
		}
		$fragments = array_values(array_filter($fragments, static function (array $fragment) use ($html): bool {
			$attribute = 'region' === $fragment['kind'] ? 'data-iikiti-region-slot' : 'data-iikiti-shell-slot';

			return str_contains($html, sprintf('%s="%s"', $attribute, $fragment['slot']));
		}));
		$visibleRoots = [];
		foreach ($fragments as $fragment) {
			$visibleRoots[$fragment['kind'].':'.$fragment['id']] = true;
		}
		$widgetPlan = $this->widgetPlan($template, $context, $regionTrees, $shells, $revision);
		foreach ($widgetPlan->descriptors() as $descriptor) {
			$entry = $widgetPlan->entry($descriptor['id']);
			if (null !== $entry && isset($visibleRoots[$entry['root_kind'].':'.$entry['root_id']])) {
				$fragments[] = $descriptor;
			}
		}
		$fragments = array_merge($fragments, $pluginFragments);
		if (count($fragments) > RenderFragmentLimits::MAX_PER_PAGE) {
			return ['html' => $this->renderError('This page declares more fragments than can be rendered.'), 'revision' => $revision, 'fragments' => []];
		}

		return ['html' => $html, 'revision' => $revision, 'fragments' => $fragments];
	}

	/**
	 * Renders exactly one API-authorized region, shell, or planned widget fragment.
	 *
	 * @param array<string,list<array<string,mixed>>> $regionTrees
	 * @return PageFragmentResult|null
	 */
	public function renderFragment(
		Template $template,
		BlockRenderContext $context,
		array $regionTrees,
		string $kind,
		string $id,
		?TemplateResolutionContext $resolutionContext = null,
		?string $expectedRevision = null,
	): ?PageFragmentResult {
		$shells = $this->resolveShells($resolutionContext);
		$revision = $this->renderRevision($template, $context, $regionTrees, $shells);
		if (null !== $expectedRevision && !hash_equals($revision, $expectedRevision)) {
			return new PageFragmentResult(new FragmentResult(''), ['revision' => $revision, 'id' => $id, 'found' => false]);
		}
		$widgetPlan = $this->widgetPlan($template, $context, $regionTrees, $shells, $revision);
		$tree = null;
		$found = false;
		$resolvedId = $id;
		$widgetEntry = null;
		if ('region' === $kind) {
			foreach ($template->getRegions() as $region) {
				$regionId = (string) ($region['id'] ?? '');
				if (('@main' === $id && 'main' === ($region['role'] ?? null)) || $id === $regionId) {
					$resolvedId = $regionId;
					$tree = $regionTrees[$resolvedId] ?? null;
					$found = true;
					break;
				}
			}
		} elseif ('shell' === $kind) {
			foreach ($shells as $roleShells) {
				foreach ($roleShells as $shell) {
					if ($id === (string) ($shell['id'] ?? '')) {
						$tree = is_array($shell['blocks'] ?? null) ? $shell['blocks'] : null;
						$found = true;
						break 2;
					}
				}
			}
		} elseif ('widget' === $kind) {
			$widgetEntry = $widgetPlan->entry($id);
			if (null !== $widgetEntry) {
				$tree = [$widgetEntry['node']];
				$found = true;
			}
		} else {
			return null;
		}

		if (!$found) {
			return null;
		}
		if (null !== $context->request) {
			$pageContext = $context->request->attributes->get(ProfilerQueryDiagnostics::RENDER_CONTEXT_ATTRIBUTE);
			if (is_string($pageContext)) {
				$context->request->attributes->set(
					ProfilerQueryDiagnostics::RENDER_CONTEXT_ATTRIBUTE,
					$pageContext.' | '.$this->fragmentProfilerLabel($template, $kind, $resolvedId, $shells, $widgetEntry),
				);
			}
		}

		if ('widget' === $kind && null !== $widgetEntry) {
			$renderWidget = function (RenderCacheTagCollector $cacheTags) use ($widgetEntry, $context, $widgetPlan): array {
				$fragmentContext = $context->withRenderCacheTags($cacheTags);
				if (null !== $this->fiberTaskRunner && null !== $this->embedResolver) {
					$fragmentContext = $fragmentContext->withPrefetchedEmbeds($this->embedResolver->prefetchOembeds(
						$this->embedUrls(['widget' => [$widgetEntry['node']]], $fragmentContext, []),
						$this->fiberTaskRunner,
					));
				}

				return [
					'html' => $this->blockRenderer->renderWidgetFragment($widgetEntry['node'], $fragmentContext, $widgetPlan, $widgetEntry['path']),
					'styles' => $fragmentContext->customCssCollector?->fragments($fragmentContext->editorMode) ?? [],
				];
			};
			$rendered = null !== $this->renderedFragmentCache
				? $this->renderedFragmentCache->remember($widgetEntry['node'], $context, $revision, $id, $renderWidget)
				: $renderWidget(new RenderCacheTagCollector());
			$html = $rendered['html'];
			$styles = $rendered['styles'];
		} else {
			if (null !== $this->fiberTaskRunner && null !== $this->embedResolver) {
				$fragmentTrees = 'region' === $kind ? [$resolvedId => $tree ?? []] : ['shell' => $tree ?? []];
				$context = $context->withPrefetchedEmbeds($this->embedResolver->prefetchOembeds(
					$this->embedUrls($fragmentTrees, $context, []),
					$this->fiberTaskRunner,
				));
			}
			$rootPath = WidgetFragmentPlan::rootPath($kind, $resolvedId);
			$html = $this->blockRenderer->renderRegionTree($tree, $context, $widgetPlan, $rootPath);
			$styles = $context->customCssCollector?->fragments($context->editorMode) ?? [];
		}

		return new PageFragmentResult(new FragmentResult($html, $styles), ['revision' => $revision, 'id' => $resolvedId]);
	}

	/**
	 * @param array<string,list<array<string,mixed>>> $shells
	 * @param array{node:array<string,mixed>,path:string,root_kind:string,root_id:string}|null $widgetEntry
	 */
	private function fragmentProfilerLabel(Template $template, string $kind, string $id, array $shells, ?array $widgetEntry): string
	{
		if ('region' === $kind) {
			foreach ($template->getRegions() as $region) {
				if ($id === (string) ($region['id'] ?? '')) {
					$name = trim((string) ($region['name'] ?? $id));
					$role = trim((string) ($region['role'] ?? ''));

					return sprintf('Region "%s" (%s%s)', '' !== $name ? $name : $id, $id, '' !== $role ? ', role '.$role : '');
				}
			}

			return sprintf('Region %s', $id);
		}

		if ('shell' === $kind) {
			foreach ($shells as $role => $roleShells) {
				foreach ($roleShells as $shell) {
					if ($id === (string) ($shell['id'] ?? '')) {
						$name = trim((string) ($shell['name'] ?? $id));

						return sprintf('Shell "%s" (%s, role %s)', '' !== $name ? $name : $id, $id, $role);
					}
				}
			}

			return sprintf('Shell %s', $id);
		}

		if ('widget' === $kind && null !== $widgetEntry) {
			$type = (string) ($widgetEntry['node']['type'] ?? 'block');
			$location = sprintf('%s %s, path %s', $widgetEntry['root_kind'], $widgetEntry['root_id'], $widgetEntry['path']);

			return sprintf('Widget %s (%s)', $type, $location);
		}

		return sprintf('%s %s', ucfirst($kind), $id);
	}

	/**
	 * Build the same deterministic widget plan for manifests and fragment requests.
	 * The budget accounts for all configured region/shell requests, including roots
	 * the selected layout may not use, so every API request makes the same choice.
	 *
	 * @param array<string,list<array<string,mixed>>> $regionTrees
	 * @param array<string,list<array<string,mixed>>> $shells
	 */
	private function widgetPlan(
		Template $template,
		BlockRenderContext $context,
		array $regionTrees,
		array $shells,
		string $revision,
	): WidgetFragmentPlan {
		$roots = $this->widgetRoots($template, $regionTrees, $shells);

		return $this->blockRenderer->planWidgetFragments(
			$roots,
			$context,
			$revision,
			max(0, RenderFragmentLimits::MAX_PER_PAGE - count($roots)),
		);
	}

	/**
	 * @param array<string,list<array<string,mixed>>> $regionTrees
	 * @param array<string,list<array<string,mixed>>> $shells
	 * @return list<array{kind:string,id:string,role:string,tree:list<array<string,mixed>>}>
	 */
	private function widgetRoots(Template $template, array $regionTrees, array $shells): array
	{
		$roots = [];
		$regions = $template->getRegions();
		$regionPriority = ['main' => 0, 'header' => 2];
		usort($regions, static fn (array $left, array $right): int =>
			($regionPriority[$left['role'] ?? ''] ?? 1) <=> ($regionPriority[$right['role'] ?? ''] ?? 1));
		foreach ($regions as $region) {
			$id = (string) ($region['id'] ?? '');
			if ('' === $id) {
				continue;
			}
			$tree = $regionTrees[$id] ?? [];
			$roots[] = [
				'kind' => 'region',
				'id' => $id,
				'role' => (string) ($region['role'] ?? ''),
				'tree' => $tree,
			];
		}

		$roles = array_keys($shells);
		$rolePriority = ['aside' => 0, 'sidebar' => 0, 'footer' => 1, 'dialog' => 2, 'header' => 3];
		usort($roles, static fn (string $left, string $right): int => ($rolePriority[$left] ?? 1) <=> ($rolePriority[$right] ?? 1));
		foreach ($roles as $role) {
			foreach ($shells[$role] as $shell) {
				$id = (string) ($shell['id'] ?? '');
				if ('' === $id) {
					continue;
				}
				$tree = $shell['blocks'] ?? [];
				$roots[] = [
					'kind' => 'shell',
					'id' => $id,
					'role' => $role,
					'tree' => is_array($tree) ? array_values($tree) : [],
				];
			}
		}

		return $roots;
	}

	/**
	 * Groups rendered shell HTML by role (`header`, `footer`, `aside`, `dialog`).
	 * Roles with no matching shell are absent, so the layout can emit no wrapper.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function resolveShells(?TemplateResolutionContext $resolutionContext): array
	{
		if (null === $this->shellResolver || null === $this->shellProvider || null === $resolutionContext) {
			return [];
		}

		$resolvedByRole = [];
		foreach ($this->shellProvider->byRole() as $role => $shells) {
			foreach ($this->shellResolver->resolve($shells, $resolutionContext) as $shell) {
				$resolvedByRole[$role][] = $shell;
			}
		}

		return $resolvedByRole;
	}

	/**
	 * @param array<string,list<array<string,mixed>>> $shells
	 * @return array<string,list<string>>
	 */
	private function renderShells(BlockRenderContext $context, array $shells): array
	{
		$byRole = [];
		foreach ($shells as $role => $roleShells) {
			foreach ($roleShells as $shell) {
				$html = $this->blockRenderer->renderRegionTree(
					is_array($shell['blocks'] ?? null) ? $shell['blocks'] : null,
					$context,
				);
				if ('' === $html && !$context->editorMode) {
					continue;
				}
				$byRole[$role][] = $html;
			}
		}

		return $byRole;
	}

	/**
	 * @param array<string,list<array<string,mixed>>> $regionTrees
	 * @param array<string,list<array<string,mixed>>> $shells
	 * @return list<string>
	 */
	private function embedUrls(array $regionTrees, BlockRenderContext $context, array $shells): array
	{
		$urls = [];
		foreach ([$regionTrees, $context->dynamicBlocks, $shells] as $tree) {
			$this->collectEmbedUrls($tree, $urls);
		}

		return array_values(array_unique($urls));
	}

	/**
	 * @param array<string,mixed>|list<mixed> $node
	 * @param list<string> $urls
	 * @param-out list<string> $urls
	 */
	private function collectEmbedUrls(array $node, array &$urls): void
	{
		if ('social_embed' === ($node['type'] ?? null)) {
			$url = $node['content']['url'] ?? null;
			if (is_string($url) && '' !== trim($url)) {
				$urls[] = $url;
			}
		}

		foreach ($node as $value) {
			if (is_array($value)) {
				$this->collectEmbedUrls($value, $urls);
			}
		}
	}

	/**
	 * @param array<string,list<array<string,mixed>>> $regionTrees
	 * @param array<string,list<array<string,mixed>>> $shells
	 */
	private function renderRevision(Template $template, BlockRenderContext $context, array $regionTrees, array $shells): string
	{
		$snapshot = [
			'template' => [
				'id' => $template->getId(),
				'layout' => $template->getLayout(),
				'regions' => $template->getRegions(),
				'settings' => $template->getSettings(),
			],
			'region_trees' => $regionTrees,
			'shells' => $shells,
			'dynamic_blocks' => $context->dynamicBlocks,
			'site' => $this->objectSnapshot($context->site),
			'object' => $this->objectSnapshot($context->object),
			'request_uri' => $context->request?->getRequestUri(),
			'editor_mode' => $context->editorMode,
			'can_edit' => $context->canEdit,
		];
		return hash('sha256', serialize($snapshot));
	}

	/** @return array{id:int|string|null,site_id:int|string,properties:array<string,mixed>}|null */
	private function objectSnapshot(?DbObject $object): ?array
	{
		if (null === $object) {
			return null;
		}

		$properties = [];
		foreach ($object->getProperties() as $property) {
			if ($property->isSecret()) {
				continue;
			}
			$properties[$property->getName()] = $property->getValue();
		}
		ksort($properties);

		return [
			'id' => $object->getId(),
			'site_id' => $object->getSiteId(),
			'properties' => $properties,
		];
	}

	/**
	 * @param list<array<string,mixed>> $regions
	 */
	private function hasMainRegion(array $regions): bool
	{
		foreach ($regions as $region) {
			if (($region['role'] ?? '') === self::REGION_ROLE_MAIN) {
				return true;
			}
		}

		return false;
	}

	private function regionVar(string $regionId): string
	{
		return 'region_'.preg_replace('/[^a-z0-9_-]/i', '_', $regionId);
	}

	private function renderNoMainRegion(BlockRenderContext $context): string
	{
		$message = 'A main content area must be provided in the template.';

		if (!$context->editorMode) {
			return '<main class="iikiti-error iikiti-error--no-main">'.
				htmlspecialchars($message, ENT_QUOTES).'</main>';
		}

		return '<div class="iikiti-editor__no-main" data-iikiti-editor-error="no_main_region">'.
			htmlspecialchars($message, ENT_QUOTES).
			'</div>';
	}

	private function renderError(string $message): string
	{
		return '<div class="iikiti-error">'.htmlspecialchars($message, ENT_QUOTES).'</div>';
	}
}
