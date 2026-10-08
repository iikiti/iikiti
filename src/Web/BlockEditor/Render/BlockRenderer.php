<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockType;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use iikiti\CMS\Web\BlockEditor\Query\QueryDefinition;
use iikiti\CMS\Web\BlockEditor\Query\QueryExecutor;
use iikiti\CMS\Web\BlockEditor\Query\QueryFieldCatalog;
use Twig\Environment;

/**
 * Renders a block tree (stored as JSON) into HTML.
 *
 * - Each block is wrapped in a `.iikiti-block` element (`BlockType::$wrapperTag`,
 *   a `div` by default but e.g. `span` for inline-only types so they stay valid
 *   inside elements like `<h2>`).
 * - In editor mode (`BlockRenderContext::editorMode`) the wrapper also carries
 *   `data-block-*` attributes (id, type, content, style, bindings) so the Svelte
 *   editor can hydrate the DOM. Public output is stripped of this metadata by
 *   construction (the attributes are only emitted in editor mode).
 * - Block *content* is rendered from the block type's Twig template; containers
 *   render their children recursively into a `children_html` placeholder.
 * - `query` blocks execute their query and render their child list once per
 *   result item (per-result template), resolving each child's dynamic field
 *   bindings against the result row (available to templates as `item`).
 */
final class BlockRenderer
{
	/* Wrapper/fallback tag for block types that do not define one. */
	private const WRAPPER_TAG = 'div';

	/**
	 * Seeded editor-mode hint rendered as the default `main` region text block.
	 * It is instructional content: visible to editors (so they can replace it)
	 * but must never leak to public visitors, so {@see renderNode()} suppresses
	 * this exact block when not in editor mode.
	 */
	private const EDIT_HINT = '<p>Edit this page with <code>?edit</code>.</p>';

	/** Schema field types whose resolved binding values may be overlaid. */
	private const BINDABLE_FIELD_TYPES = ['text', 'textarea', 'richtext', 'url'];

	/** @var array<string,true> query block ids currently being rendered (cycle guard) */
	private array $activeQueryIds = [];

	public function __construct(
		private readonly BlockTypeRegistry $registry,
		private readonly Environment $twig,
		private readonly QueryExecutor $queryExecutor,
		private readonly QueryFieldCatalog $queryFields,
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
			return $this->wrap($this->placeholder($type), $node, $context, null);
		}

		$content = is_array($node['content'] ?? null) ? $node['content'] : [];
		$children = $node['children'] ?? [];

		// The seeded "Edit this page with ?edit" hint is instructional content for
		// editors only; suppress it from visitors who cannot edit (so it only
		// appears to logged-in editors, with or without `?edit`).
		if (!$context->canEdit && 'text' === $type && ($content['content'] ?? '') === self::EDIT_HINT) {
			return '';
		}

		// `dynamic` blocks source their children from the object's per-object
		// dynamic block storage (keyed by the block id) rather than the template tree.
		if (($node['type'] ?? '') === 'dynamic') {
			$dynamicId = (string) ($node['id'] ?? '');
			$dynamicChildren = $context->dynamicBlocks[$dynamicId] ?? $children;
			$children = is_array($dynamicChildren) ? $dynamicChildren : [];
		}

		// `query` blocks act as per-result templates. Nested queries (inside another
		// query's row loop) fall back to the legacy rendering to bound the work.
		$extraVars = [];
		if ('query' === $type && $blockType->acceptsChildren && is_array($children) && [] !== $children && 0 === $context->queryDepth) {
			$extraVars = $this->queryRowVars($node, $content, $children, $context);
		}

		// Per-item dynamic bindings: overlay resolved row values onto the block's
		// static content before the template renders it (null = keep static).
		if (null !== $context->item) {
			$content = $this->resolveBindings($node, $content, $context->item, $blockType);
		}

		$childrenHtml = ($blockType->acceptsChildren && is_array($children) && [] === $extraVars) ?
			$this->renderTree($children, $context) :
			'';

		$inner = $this->renderTemplate($blockType, $node, $content, $childrenHtml, $context, $extraVars);

		return $this->wrap($inner, $node, $context, $blockType);
	}

	/**
	 * Execute a query block and render its stored child list once per result row.
	 *
	 * - A failing query degrades to the empty state instead of failing the page.
	 * - A block that is already being rendered (self-referencing tree data) is
	 *   rendered as a placeholder instead of recursing.
	 * - In editor mode the child template is always emitted (even with zero rows)
	 *   so an editor draft saved while the query is empty keeps its children.
	 *
	 * @param array<string,mixed>   $node
	 * @param array<string,mixed>   $content
	 * @param list<array<string,mixed>> $children
	 * @return array<string,mixed> template variables (`items`, `children_items`, `children_template`)
	 */
	private function queryRowVars(array $node, array $content, array $children, BlockRenderContext $context): array
	{
		$blockId = (string) ($node['id'] ?? '');
		if ('' !== $blockId && isset($this->activeQueryIds[$blockId])) {
			return ['items' => [], 'children_items' => [], 'query_error' => 'Self-referencing query block.'];
		}

		try {
			$items = $this->queryExecutor->execute(QueryDefinition::fromArray($content), $this->siteIdFor($context));
		} catch (\Throwable) {
			$items = [];
		}

		$childrenItems = [];
		if ('' !== $blockId) {
			$this->activeQueryIds[$blockId] = true;
		}
		try {
			foreach ($items as $item) {
				$childrenItems[] = $this->renderTree($children, $context->withItem($item));
			}
		} finally {
			if ('' !== $blockId) {
				unset($this->activeQueryIds[$blockId]);
			}
		}

		$vars = ['items' => $items, 'children_items' => $childrenItems];
		if ($context->editorMode) {
			$vars['children_template'] = $this->renderTree($children, $context);
		}

		return $vars;
	}

	/**
	 * @param array<string,mixed> $node
	 */
	private function wrap(string $inner, array $node, BlockRenderContext $context, ?BlockType $blockType): string
	{
		$type = (string) ($node['type'] ?? '');
		$sanitizedType = preg_replace('/[^a-z0-9_-]/i', '-', $type);
		$element = is_array($node['element'] ?? null) ? $node['element'] : [];
		$class = 'iikiti-block iikiti-block--'.$sanitizedType;

		$extraClass = is_string($element['cssClass'] ?? null) ? trim($element['cssClass']) : '';
		if ('' !== $extraClass) {
			$class .= ' '.$extraClass;
		}

		$html = ' class="'.htmlspecialchars($class, ENT_QUOTES).'"';

		$elementId = is_string($element['id'] ?? null) ? trim($element['id']) : '';
		if ('' !== $elementId) {
			$html .= ' id="'.htmlspecialchars($elementId, ENT_QUOTES).'"';
		}

		$html .= $this->renderElementAttributes($element);

		if ($context->editorMode) {
			$html .= ' data-block-id="'.htmlspecialchars((string) ($node['id'] ?? ''), ENT_QUOTES).'"';
			$html .= ' data-block-type="'.htmlspecialchars($type, ENT_QUOTES).'"';
			$contentJson = $this->safeJson($node['content'] ?? null);
			$styleJson = $this->safeJson($node['style'] ?? null);
			$elementJson = $this->safeJson($node['element'] ?? null);
			$bindingsJson = $this->safeJson($node['bindings'] ?? null);
			$html .= ' data-block-content="'.$contentJson.'"';
			$html .= ' data-block-style="'.$styleJson.'"';
			$html .= ' data-block-element="'.$elementJson.'"';
			$html .= ' data-block-bindings="'.$bindingsJson.'"';
		}

		$tag = $blockType->wrapperTag ?? self::WRAPPER_TAG;
		if (1 !== preg_match('/^[a-z][a-z0-9-]*$/i', $tag)) {
			$tag = self::WRAPPER_TAG;
		}

		return '<'.$tag.$html.'>'.$inner.'</'.$tag.'>';
	}

	/**
	 * Render the user-editable arbitrary HTML attributes onto the block wrapper.
	 * Applies an allowlist/deny-list to prevent attribute-injection XSS: attribute
	 * names must be valid, `on*` event handlers and `style` are rejected, and
	 * `javascript:`/`data:` URL schemes are stripped from values.
	 *
	 * @param array<string,mixed> $element
	 */
	private function renderElementAttributes(array $element): string
	{
		$attributes = $element['attributes'] ?? null;
		if (!is_array($attributes)) {
			return '';
		}

		$out = '';
		foreach ($attributes as $attribute) {
			if (!is_array($attribute)) {
				continue;
			}
			$name = (string) ($attribute['name'] ?? '');
			$value = (string) ($attribute['value'] ?? '');
			if ('' === $name || !$this->isSafeAttributeName($name) || !$this->isSafeAttributeValue($value)) {
				continue;
			}
			$out .= ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES).'"';
		}

		return $out;
	}

	private function isSafeAttributeName(string $name): bool
	{
		if (1 !== preg_match('/^[a-z][a-z0-9:_-]*$/i', $name)) {
			return false;
		}

		if (str_starts_with(strtolower($name), 'on')) {
			return false;
		}

		return 'style' !== strtolower($name);
	}

	private function isSafeAttributeValue(string $value): bool
	{
		$trimmed = strtolower(trim($value));

		return !str_starts_with($trimmed, 'javascript:') && !str_starts_with($trimmed, 'data:');
	}

	/**
	 * @param array<string,mixed> $node
	 * @param array<string,mixed> $content
	 * @param array<string,mixed> $extraVars Additional template variables (e.g.
	 *                                       `items`/`children_items` for query blocks)
	 */
	private function renderTemplate(
		BlockType $blockType,
		array $node,
		array $content,
		string $childrenHtml,
		BlockRenderContext $context,
		array $extraVars = [],
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
				// The query result row currently being rendered (per-result
				// template rendering); null outside a `query` child context.
				'item' => $context->item,
			] + $extraVars);
		} catch (\Throwable $e) {
			return $this->placeholder($blockType->type, $e->getMessage());
		}
	}

	/**
	 * Overlay resolved dynamic bindings onto the block's static content. Keys
	 * must already exist in the content map (they come from the block type's
	 * schema). Resolved values are reduced to plain text here (markup stripped)
	 * so a bound field can never inject tags — even into `|raw` templates —
	 * while `|e` templates escape what remains exactly once at output.
	 *
	 * @param array<string,mixed> $node
	 * @param array<string,mixed> $content
	 * @return array<string,mixed>
	 */
	private function resolveBindings(array $node, array $content, mixed $item, BlockType $blockType): array
	{
		$bindings = is_array($node['bindings'] ?? null) ? $node['bindings'] : [];
		if ([] === $bindings) {
			return $content;
		}

		// Only text-like schema fields are bind targets. Select/number fields are
		// never overlaid: a row value must not reach a markup-position context such
		// as the heading level interpolated into the tag name.
		$bindable = [];
		foreach ($blockType->contentFields as $field) {
			if (in_array($field['type'] ?? '', self::BINDABLE_FIELD_TYPES, true)) {
				$bindable[(string) ($field['key'] ?? '')] = true;
			}
		}

		foreach ($bindings as $fieldKey => $spec) {
			$key = is_string($fieldKey) ? $fieldKey : '';
			if ('' === $key || !isset($bindable[$key]) || !is_string($spec)) {
				continue;
			}
			$value = $this->queryFields->resolve($item, $spec);
			if (null !== $value) {
				$content[$key] = strip_tags($value);
			}
		}

		return $content;
	}

	/**
	 * Site scope for query execution: the render context's site, falling back to
	 * the current request's site (mirrors BlockTwigExtension::query()). Outside
	 * a site-scoped request (e.g. plain unit rendering) queries run unscoped.
	 */
	private function siteIdFor(BlockRenderContext $context): ?int
	{
		$site = $context->site;
		if (null === $site) {
			try {
				if (SiteRegistry::hasCurrent()) {
					$site = SiteRegistry::getCurrent();
				}
			} catch (\Throwable) {
				return null; // no site scope active
			}
		}

		return null !== $site ? (int) $site->getId() : null;
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
