<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template;

use Dom\DocumentFragment;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Profiler\AsyncResponseDataCollector;
use iikiti\CMS\Response\Composition\HtmlFragmentComposer;
use iikiti\CMS\Web\BlockEditor\Embed\EmbedResolver;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderer;
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
