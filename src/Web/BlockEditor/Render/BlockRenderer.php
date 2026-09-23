<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

use iikiti\CMS\Web\BlockEditor\BlockType\BlockType;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use Twig\Environment;

/**
 * Renders a block tree (stored as JSON) into HTML.
 *
 * - Each block is wrapped in a `.iikiti-block` element.
 * - In editor mode (`BlockRenderContext::editorMode`) the wrapper also carries
 *   `data-block-*` attributes (id, type, content, style) so the Svelte editor can
 *   hydrate the existing DOM. Public output is stripped of this metadata by
 *   construction (the attributes are only emitted in editor mode).
 * - Block *content* is rendered from the block type's Twig template; containers
 *   render their children recursively into a `children_html` placeholder.
 */
final class BlockRenderer
{
	private const WRAPPER_TAG = 'div';

	public function __construct(
		private readonly BlockTypeRegistry $registry,
		private readonly Environment $twig,
	) {
	}

	/**
	 * @param list<array<string,mixed>> $tree Ordered list of block nodes
	 */
	public function renderTree(?array $tree, BlockRenderContext $context): string
	{
		if (empty($tree)) {
			return '';
		}

		$html = '';
		foreach ($tree as $node) {
			$html .= $this->renderNode($node, $context);
		}

		return $html;
	}

	/**
	 * @param array<string,mixed> $node
	 */
	public function renderNode(array $node, BlockRenderContext $context): string
	{
		$type = (string) ($node['type'] ?? '');
		$blockType = $this->registry->get($type);

		// Unknown block type: render a safe placeholder but still emit metadata so
		// the editor recognises the node.
		if (null === $blockType) {
			return $this->wrap($this->placeholder($type), $node, $context);
		}

		$content = is_array($node['content'] ?? null) ? $node['content'] : [];
		$children = $node['children'] ?? [];

		// `dynamic` blocks source their children from the object's per-object
		// dynamic block storage (keyed by the block id) rather than the template tree.
		if (($node['type'] ?? '') === 'dynamic') {
			$dynamicId = (string) ($node['id'] ?? '');
			$dynamicChildren = $context->dynamicBlocks[$dynamicId] ?? $children;
			$children = is_array($dynamicChildren) ? $dynamicChildren : [];
		}

		$childrenHtml = ($blockType->acceptsChildren && is_array($children)) ?
			$this->renderTree($children, $context) :
			'';

		$inner = $this->renderTemplate($blockType, $node, $content, $childrenHtml, $context);

		return $this->wrap($inner, $node, $context);
	}

	/**
	 * @param array<string,mixed> $node
	 */
	private function wrap(string $inner, array $node, BlockRenderContext $context): string
	{
		$type = (string) ($node['type'] ?? '');
		$sanitizedType = preg_replace('/[^a-z0-9_-]/i', '-', $type);
		$class = 'iikiti-block iikiti-block--'.$sanitizedType;

		$html = ' class="'.htmlspecialchars($class, ENT_QUOTES).'"';

		if ($context->editorMode) {
			$html .= ' data-block-id="'.htmlspecialchars((string) ($node['id'] ?? ''), ENT_QUOTES).'"';
			$html .= ' data-block-type="'.htmlspecialchars($type, ENT_QUOTES).'"';
			$contentJson = $this->safeJson($node['content'] ?? null);
			$styleJson = $this->safeJson($node['style'] ?? null);
			$html .= ' data-block-content="'.$contentJson.'"';
			$html .= ' data-block-style="'.$styleJson.'"';
		}

		return '<'.self::WRAPPER_TAG.$html.'>'.$inner.'</'.self::WRAPPER_TAG.'>';
	}

	/**
	 * @param array<string,mixed> $node
	 * @param array<string,mixed> $content
	 */
	private function renderTemplate(
		BlockType $blockType,
		array $node,
		array $content,
		string $childrenHtml,
		BlockRenderContext $context,
	): string {
		if (null === $blockType->renderTemplate) {
			return $this->placeholder($blockType->type);
		}

		try {
			return $this->twig->render($blockType->renderTemplate, [
				'block' => $node,
				'content' => $content,
				'style' => $this->styleForOutput($node['style'] ?? []),
				'children_html' => $childrenHtml,
				'editor_mode' => $context->editorMode,
				'site' => $context->site,
				'object' => $context->object,
			]);
		} catch (\Throwable $e) {
			return $this->placeholder($blockType->type, $e->getMessage());
		}
	}

	private function placeholder(string $type, ?string $error = null): string
	{
		$msg = $error ?
			sprintf('Block of type "%s" failed to render: %s', $type, $error) :
			sprintf('Block of type "%s" has no renderer.', $type);

		return '<em class="iikiti-block--placeholder">'.htmlspecialchars($msg, ENT_QUOTES).'</em>';
	}

	private function safeJson(mixed $value): string
	{
		$encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return htmlspecialchars((string) $encoded, ENT_QUOTES);
	}

	/**
	 * Reduce the per-breakpoint style map to a flat inline `style` attribute string
	 * for the `base` breakpoint. The editor reads the full map from
	 * `data-block-style`; the public render only needs base styles here.
	 *
	 * @param array<string,mixed> $style
	 */
	private function styleForOutput(array $style): string
	{
		$base = $style['base'] ?? [];
		if (!is_array($base)) {
			return '';
		}

		$pairs = [];
		foreach ($base as $key => $value) {
			if (!is_string($key) || null === $value) {
				continue;
			}
			$property = $this->cssProperty($key);
			$pairs[] = $property.':'.htmlspecialchars((string) $value, ENT_QUOTES).';';
		}

		return implode('', $pairs);
	}

	private function cssProperty(string $key): string
	{
		$kebab = preg_replace('/([a-z])([A-Z])/', '$1-$2', $key);

		return strtolower((string) $kebab);
	}
}
