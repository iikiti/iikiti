<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template;

use iikiti\CMS\Entity\Object\Template;
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
	) {
	}

	/**
	 * Renders the template's layout with the given trees (published by default;
	 * pass draft trees when editing).
	 *
	 * @param array<string,list<array<string,mixed>>> $regionTrees per-region block trees
	 * @param array<string,mixed>                     $settings
	 */
	public function render(Template $template, BlockRenderContext $context, array $regionTrees, array $settings = []): string
	{
		$regions = $template->getRegions();
		if ([] !== $regions && !$this->hasMainRegion($regions)) {
			return $this->renderNoMainRegion($context);
		}

		$vars = [
			'iikiti_editor_mode' => $context->editorMode,
			'iikiti_regions' => $regions,
			'iikiti_settings' => $settings ?: $template->getSettings(),
			'iikiti_site' => $context->site,
		];

		foreach ($regions as $region) {
			$regionId = (string) ($region['id'] ?? '');
			if ('' === $regionId) {
				continue;
			}
			$var = $this->regionVar($regionId);
			$tree = $regionTrees[$regionId] ?? null;
			$vars[$var] = $this->blockRenderer->renderTree($tree, $context);
		}

		$this->twig->addGlobal('iikiti_editor_mode', $context->editorMode);

		try {
			return $this->twig->render($template->getLayout(), $vars);
		} catch (LoaderError|RuntimeError|SyntaxError $e) {
			return $this->renderError($e->getMessage());
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
