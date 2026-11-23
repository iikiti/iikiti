<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template;

use Dom\DocumentFragment;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Profiler\AsyncResponseDataCollector;
use iikiti\CMS\Profiler\ProfilerQueryDiagnostics;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderer;
use iikiti\CMS\Web\BlockEditor\Render\RenderCacheTagCollector;
use iikiti\CMS\Web\BlockEditor\Render\RenderedFragmentCache;
use iikiti\CMS\Web\BlockEditor\Render\RootContainerRule;
use iikiti\CMS\Web\BlockEditor\RenderFragmentLimits;
use iikiti\CMS\Web\BlockEditor\Render\WidgetFragmentPlan;
use iikiti\CMS\Web\Fragment\FragmentContext;
use iikiti\CMS\Web\Fragment\FragmentPlanCollector;
use iikiti\CMS\Web\Fragment\FragmentResult;
use iikiti\CMS\Web\Fragment\PageFragmentResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
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

	/**
	 * @param iterable<TemplateRenderPrefetchInterface> $renderPrefetchers
	 */
	public function __construct(
		private readonly BlockRenderer $blockRenderer,
		private readonly Environment $twig,
		private readonly ?ShellResolver $shellResolver = null,
		private readonly ?ShellProvider $shellProvider = null,
		private readonly ?FiberTaskRunner $fiberTaskRunner = null,
		private readonly ?AsyncResponseDataCollector $profiler = null,
		private readonly ?RenderedFragmentCache $renderedFragmentCache = null,
		#[AutowireIterator('iikiti.cms.template_render_prefetch')]
		private readonly iterable $renderPrefetchers = [],
		private readonly ?PluginActivationChecker $activationChecker = null,
		private readonly ?PluginRegistry $pluginRegistry = null,
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
		if (null !== $this->fiberTaskRunner) {
			$noopRender = static function (BlockRenderContext $ctx): void {};
			foreach ($this->renderPrefetchers as $prefetcher) {
				$context = $prefetcher->prefetchDuringRender($regionTrees, $shells, $context, $this->fiberTaskRunner, $noopRender);
			}
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
			return $this->twig->render($this->resolveLayoutTemplate($template->getLayout(), $context), $vars);
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
		$shells = $this->resolveShells($resolutionContext);
		$renderLayoutAndFragments = function (BlockRenderContext $renderContext) use (
			$template,
			$regions,
			$regionTrees,
			$shells,
			$composer,
			$pagePlaceholderSelector,
			$settings,
		): void {
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

			$regionSlots = [];
			$vars = [
				'iikiti_editor_mode' => $renderContext->editorMode,
				'iikiti_can_edit' => $renderContext->canEdit,
				'iikiti_regions' => $regions,
				'iikiti_settings' => $settings ?: $template->getSettings(),
				'iikiti_site' => $renderContext->site,
				'_iikiti_fragment_context' => new FragmentContext($renderContext->site, $renderContext->request?->getLocale() ?? 'und'),
				'_iikiti_fragment_styles' => $renderContext->customCssCollector,
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
				$layout = $this->twig->render($this->resolveLayoutTemplate($template->getLayout(), $renderContext), $vars);
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

			$roles = array_keys($shellSlots);
			$rolePriority = ['aside' => 0, 'sidebar' => 0, 'footer' => 1, 'dialog' => 2, 'header' => 3];
			usort($roles, static fn (string $left, string $right): int => ($rolePriority[$left] ?? 1) <=> ($rolePriority[$right] ?? 1));
			foreach ($roles as $role) {
				foreach ($shellSlots[$role] as $entry) {
					$html = $this->blockRenderer->renderRegionTree(
						is_array($entry['shell']['blocks'] ?? null) ? $entry['shell']['blocks'] : null,
						$renderContext,
					);
					$selector = sprintf('template[data-iikiti-shell-slot="%s"]', $entry['token']);
					if ($composer->hasPlaceholder($selector)) {
						$composer->insertFragmentIntoPlaceholder($selector, $composer->createFragment($html));
					}
				}
			}
		};

		$pipeline = static function (BlockRenderContext $finalContext) use ($renderLayoutAndFragments): BlockRenderContext {
			$renderLayoutAndFragments($finalContext);

			return $finalContext;
		};
		if (null !== $this->fiberTaskRunner) {
			$runner = $this->fiberTaskRunner;
			$prefetchers = is_array($this->renderPrefetchers)
				? $this->renderPrefetchers
				: iterator_to_array($this->renderPrefetchers, false);
			foreach (array_reverse($prefetchers) as $prefetcher) {
				$next = $pipeline;
				$pipeline = static fn (BlockRenderContext $ctx): BlockRenderContext => $prefetcher->prefetchDuringRender(
					$regionTrees,
					$shells,
					$ctx,
					$runner,
					$next,
					$composer,
				);
			}
		}
		$pipeline($context);
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

		$widgetPlan = $this->widgetPlan($template, $context, $regionTrees, $shells, $revision);
		$fragments = [];
		$regionPlaceholders = [];
		/** @var array<string,list<string>> $inlinedRootSlots */
		$inlinedRootSlots = [];
		$orderedRegions = $regions;
		$regionPriority = ['main' => 0, 'header' => 2];
		usort($orderedRegions, static fn (array $left, array $right): int =>
			($regionPriority[$left['role'] ?? ''] ?? 1) <=> ($regionPriority[$right['role'] ?? ''] ?? 1));
		foreach ($orderedRegions as $region) {
			$regionId = (string) ($region['id'] ?? '');
			if ('' === $regionId) {
				continue;
			}

			$tree = $regionTrees[$regionId] ?? [];
			$widgetSlots = $this->inlinedWidgetSlots('region', $regionId, $tree, $context, $widgetPlan);
			if (null !== $widgetSlots) {
				$inlinedRootSlots['region:'.$regionId] = $widgetSlots;
				$regionPlaceholders[$regionId] = $this->renderWidgetSlotPlaceholders($widgetSlots);
				continue;
			}

			$slot = bin2hex(random_bytes(16));
			$regionPlaceholders[$regionId] = sprintf('<template data-iikiti-region-slot="%s"></template>', $slot);
			$fragments[] = ['kind' => 'region', 'id' => $regionId, 'role' => (string) ($region['role'] ?? ''), 'slot' => $slot];
		}

		$shellPlaceholders = [];
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

				$tree = is_array($shell['blocks'] ?? null) ? array_values($shell['blocks']) : [];
				$widgetSlots = $this->inlinedWidgetSlots('shell', $shellId, $tree, $context, $widgetPlan);
				if (null !== $widgetSlots) {
					$inlinedRootSlots['shell:'.$shellId] = $widgetSlots;
					$shellPlaceholders[$role][] = $this->renderWidgetSlotPlaceholders($widgetSlots);
					continue;
				}

				$slot = bin2hex(random_bytes(16));
				$shellPlaceholders[$role][] = sprintf('<template data-iikiti-shell-slot="%s"></template>', $slot);
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
		foreach ($regionPlaceholders as $regionId => $placeholder) {
			$vars[$this->regionVar($regionId)] = $placeholder;
		}
		foreach ($shellPlaceholders as $role => $rolePlaceholders) {
			foreach ($rolePlaceholders as $placeholder) {
				$vars['iikiti_shells'][$role][] = $placeholder;
			}
		}

		try {
			$html = $this->twig->render($this->resolveLayoutTemplate($template->getLayout(), $context), $vars);
			$pluginFragments = $fragmentPlan->serverDescriptors();
		} catch (LoaderError|RuntimeError|SyntaxError|\InvalidArgumentException $exception) {
			$html = $this->renderError($exception->getMessage());
			$fragments = [];
			$pluginFragments = [];
			$inlinedRootSlots = [];
		}
		$fragments = array_values(array_filter($fragments, static function (array $fragment) use ($html): bool {
			$attribute = 'region' === $fragment['kind'] ? 'data-iikiti-region-slot' : 'data-iikiti-shell-slot';

			return str_contains($html, sprintf('%s="%s"', $attribute, $fragment['slot']));
		}));
		$visibleRoots = [];
		foreach ($fragments as $fragment) {
			$visibleRoots[$fragment['kind'].':'.$fragment['id']] = true;
		}
		foreach ($inlinedRootSlots as $rootKey => $slots) {
			foreach ($slots as $slot) {
				if (str_contains($html, sprintf('data-iikiti-widget-slot="%s"', $slot))) {
					$visibleRoots[$rootKey] = true;
					break;
				}
			}
		}
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
				if (null !== $this->fiberTaskRunner) {
					$noopRender = static function (BlockRenderContext $ctx): void {};
					$widgetTree = ['widget' => [$widgetEntry['node']]];
					foreach ($this->renderPrefetchers as $prefetcher) {
						$fragmentContext = $prefetcher->prefetchDuringRender($widgetTree, [], $fragmentContext, $this->fiberTaskRunner, $noopRender);
					}
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
			if (null !== $this->fiberTaskRunner
				&& null === $this->inlinedWidgetSlots($kind, $resolvedId, $tree ?? [], $context, $widgetPlan)) {
				$fragmentTrees = 'region' === $kind ? [$resolvedId => $tree ?? []] : ['shell' => $tree ?? []];
				$noopRender = static function (BlockRenderContext $ctx): void {};
				foreach ($this->renderPrefetchers as $prefetcher) {
					$context = $prefetcher->prefetchDuringRender($fragmentTrees, [], $context, $this->fiberTaskRunner, $noopRender);
				}
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
	 * Returns top-level widget slot tokens when a non-empty root tree renders
	 * exclusively planned widget placeholders (zero inline HTML or root styles),
	 * allowing the manifest to embed those widget slots directly and skip an
	 * extra empty region/shell fragment request.
	 *
	 * @param list<array<string,mixed>> $tree
	 * @return list<string>|null
	 */
	private function inlinedWidgetSlots(
		string $rootKind,
		string $rootId,
		array $tree,
		BlockRenderContext $context,
		WidgetFragmentPlan $widgetPlan,
	): ?array {
		if ([] === $tree) {
			return null;
		}

		$rootPath = WidgetFragmentPlan::rootPath($rootKind, $rootId);
		$slots = [];
		foreach ($tree as $index => $node) {
			$type = (string) ($node['type'] ?? '');
			if (!$context->editorMode && !RootContainerRule::isAllowedAtRoot($type)) {
				continue;
			}
			$descriptor = $widgetPlan->descriptorAtPath(WidgetFragmentPlan::childPath($rootPath, (int) $index));
			if (null === $descriptor) {
				return null;
			}
			$slots[] = $descriptor['slot'];
		}

		return [] === $slots ? null : $slots;
	}

	/** @param list<string> $slots */
	private function renderWidgetSlotPlaceholders(array $slots): string
	{
		$html = '';
		foreach ($slots as $slot) {
			$html .= '<template data-iikiti-widget-slot="'.htmlspecialchars($slot, ENT_QUOTES).'"></template>';
		}

		return $html;
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
			'locale' => $context->request?->getLocale(),
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

	private function resolveLayoutTemplate(string $layout, BlockRenderContext $context): string
	{
		$fallback = 'base/fallback-block-editor-content.twig';
		$loader = $this->twig->getLoader();
		$ownerSlug = $this->pluginSlugForLayoutNamespace($layout);
		$pluginInactive = null !== $ownerSlug && null !== $this->activationChecker && !$this->activationChecker->isActiveForSite($ownerSlug, $context->site);
		$needsFallback = $pluginInactive || (Template::DEFAULT_LAYOUT === $layout && !$loader->exists($layout));
		if ($needsFallback && $loader->exists($fallback)) {
			return $fallback;
		}

		return $layout;
	}

	private function pluginSlugForLayoutNamespace(string $layout): ?string
	{
		if (1 !== preg_match('/^@([A-Za-z0-9_]+)\//', $layout, $matches) || null === $this->pluginRegistry) {
			return null;
		}

		$targetNamespace = strtolower($matches[1]);
		foreach ($this->pluginRegistry->getManifests() as $manifest) {
			$lastSeparator = strrpos($manifest->bundleClass, '\\');
			$shortClass = false === $lastSeparator ? $manifest->bundleClass : substr($manifest->bundleClass, $lastSeparator + 1);
			$twigNamespace = strtolower((string) preg_replace('/Bundle$/', '', $shortClass));
			if ('' !== $twigNamespace && $twigNamespace === $targetNamespace) {
				return $manifest->slug;
			}
		}

		return null;
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
