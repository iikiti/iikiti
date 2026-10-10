<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockType;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use iikiti\CMS\Web\BlockEditor\Query\QueryDefinition;
use iikiti\CMS\Web\BlockEditor\Query\QueryExecutor;
use iikiti\CMS\Web\BlockEditor\Query\QueryFieldCatalog;
use iikiti\CMS\Web\BlockEditor\ResponsiveBreakpoints;
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

	private const CSS_LENGTH_PROPERTIES = [
		'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'margin', 'padding', 'gap',
		'row-gap', 'column-gap', 'font-size', 'border-width', 'border-radius', 'top', 'right', 'bottom', 'left',
	];

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
	/**
	 * Render a region's root tree. Only containers are valid at the root
	 * (RootContainerRule); invalid root nodes are skipped publicly so they never
	 * render outside a container. Editors still see them, and the save-time
	 * validation reports them.
	 *
	 * @param list<array<string,mixed>> $tree
	 */
	public function renderRegionTree(?array $tree, BlockRenderContext $context): string
	{
		if (empty($tree)) {
			return '';
		}

		$rules = [];
		$nodes = [];
		foreach ($tree as $node) {
			$type = (string) ($node['type'] ?? '');
			if ($context->editorMode || RootContainerRule::isAllowedAtRoot($type)) {
				$nodes[] = $node;
			}
		}

		return $this->renderNodesWithRules($nodes, $context, $rules).$this->responsiveStylesheet($rules);
	}

	/**
	 * Render a nested (child) block list. No root rule applies here: containers
	 * may hold any block type.
	 *
	 * @param list<array<string,mixed>> $tree Ordered list of block nodes
	 */
	public function renderTree(?array $tree, BlockRenderContext $context): string
	{
		if (empty($tree)) {
			return '';
		}

		$rules = [];

		return $this->renderNodesWithRules($tree, $context, $rules).$this->responsiveStylesheet($rules);
	}

	/**
	 * @param list<array<string,mixed>>                                                      $nodes
	 * @param array<string,array<string,array{width:int,declarations:array<string,string>}>> $rules
	 */
	private function renderNodesWithRules(array $nodes, BlockRenderContext $context, array &$rules): string
	{
		$html = '';
		foreach ($nodes as $node) {
			$html .= $this->renderNodeWithRules($node, $context, $rules);
		}

		return $html;
	}

	/**
	 * @param array<string,mixed> $node
	 */
	public function renderNode(array $node, BlockRenderContext $context): string
	{
		$rules = [];

		return $this->renderNodeWithRules($node, $context, $rules).$this->responsiveStylesheet($rules);
	}

	/**
	 * @param array<string,mixed>                                                            $node
	 * @param array<string,array<string,array{width:int,declarations:array<string,string>}>> $rules
	 */
	private function renderNodeWithRules(array $node, BlockRenderContext $context, array &$rules): string
	{
		$type = (string) ($node['type'] ?? '');
		$blockType = $this->registry->get($type);

		// Unknown block type: render a safe placeholder but still emit metadata so
		// the editor recognises the node.
		if (null === $blockType) {
			return $this->wrap($this->placeholder($type), $node, $context, null, [], $rules);
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
			$extraVars = $this->queryRowVars($node, $content, $children, $context, $rules);
		}

		// Per-item dynamic bindings: overlay resolved row values onto the block's
		// static content before the template renders it (null = keep static).
		if (null !== $context->item) {
			$content = $this->resolveBindings($node, $content, $context->item, $blockType);
		}

		$childrenHtml = ($blockType->acceptsChildren && is_array($children) && [] === $extraVars) ?
			$this->renderNodesWithRules($children, $context, $rules) :
			'';

		$inner = $this->renderTemplate($blockType, $node, $content, $childrenHtml, $context, $extraVars);

		return $this->wrap($inner, $node, $context, $blockType, $content, $rules);
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
	 * @param array<string,mixed>                                                            $node
	 * @param array<string,mixed>                                                            $content
	 * @param list<array<string,mixed>>                                                      $children
	 * @param array<string,array<string,array{width:int,declarations:array<string,string>}>> $rules
	 *
	 * @return array<string,mixed> template variables (`items`, `children_items`, `children_template`)
	 */
	private function queryRowVars(array $node, array $content, array $children, BlockRenderContext $context, array &$rules): array
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
				$childrenItems[] = $this->renderNodesWithRules($children, $context->withItem($item), $rules);
			}
		} finally {
			if ('' !== $blockId) {
				unset($this->activeQueryIds[$blockId]);
			}
		}

		$vars = ['items' => $items, 'children_items' => $childrenItems];
		if ($context->editorMode) {
			$vars['children_template'] = $this->renderNodesWithRules($children, $context, $rules);
		}

		return $vars;
	}

	/**
	 * CSS class naming one block, derived from its node id. Ids made only of safe
	 * characters are used as-is; any other id gets a short hash of the original
	 * so distinct ids stay distinct after sanitizing.
	 */
	public static function blockIdClass(string $blockId): string
	{
		$safe = preg_replace('/[^a-z0-9_-]/i', '-', $blockId);
		if ($safe !== $blockId) {
			$safe .= '-'.substr(hash('sha256', $blockId), 0, 8);
		}

		return 'iikiti-block-id--'.$safe;
	}

	/**
	 * @param array<string,mixed>                                                            $node
	 * @param array<string,array<string,array{width:int,declarations:array<string,string>}>> $rules
	 */
	private function wrap(string $inner, array $node, BlockRenderContext $context, ?BlockType $blockType, mixed $content, array &$rules): string
	{
		$type = (string) ($node['type'] ?? '');
		$sanitizedType = preg_replace('/[^a-z0-9_-]/i', '-', $type);
		$element = is_array($node['element'] ?? null) ? $node['element'] : [];
		$class = 'iikiti-block iikiti-block--'.$sanitizedType;

		// Per-block class so styles can target one specific block. Built from the
		// node id; a hash suffix is added only when sanitizing changed the id, so
		// two distinct ids can never collapse onto the same class.
		$blockId = (string) ($node['id'] ?? '');
		if ('' !== $blockId) {
			$class .= ' '.self::blockIdClass($blockId);
		}

		$extraClass = is_string($element['cssClass'] ?? null) ? trim($element['cssClass']) : '';
		if ('' !== $extraClass) {
			$class .= ' '.$extraClass;
		}

		$html = ' class="'.htmlspecialchars($class, ENT_QUOTES).'"';
		$hasResponsiveStyle = $this->hasResponsiveStyles($node, $blockType, $type);
		$style = $this->styleForOutput(is_array($node['style'] ?? null) ? $node['style'] : [], $blockType, $type, $hasResponsiveStyle);
		if ('' !== $style && !in_array($type, ['container', 'query', 'icon', 'image'], true)) {
			$html .= ' style="'.htmlspecialchars($style, ENT_QUOTES).'"';
		}

		$elementId = is_string($element['id'] ?? null) ? trim($element['id']) : '';
		if ('' !== $elementId) {
			$html .= ' id="'.htmlspecialchars($elementId, ENT_QUOTES).'"';
		}

		$html .= $this->renderElementAttributes($element, $blockType);
		$html .= $this->renderWrapperContentAttributes($blockType, $content);

		if ($blockType?->childrenInWrapper) {
			$html .= ' data-block-children';
		}

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

		$this->collectResponsiveStyles($node, $blockType, $type, $rules);

		return '<'.$tag.$html.'>'.$inner.'</'.$tag.'>';
	}

	private function renderWrapperContentAttributes(?BlockType $blockType, mixed $content): string
	{
		if (null === $blockType || !is_array($content)) {
			return '';
		}
		$out = '';
		foreach ($blockType->contentFields as $field) {
			if (($field['wrapperAttribute'] ?? false) !== true) {
				continue;
			}
			$key = is_string($field['key'] ?? null) ? $field['key'] : '';
			if ('' === $key || !$this->isSafeAttributeName($key)) {
				continue;
			}
			$value = $content[$key] ?? $field['default'] ?? null;
			if ('method' === strtolower($key)) {
				$value = is_string($value) ? strtolower(trim($value)) : '';
				if (!in_array($value, ['get', 'post'], true)) {
					$value = 'post';
				}
			} elseif ('action' === strtolower($key)) {
				if (!is_string($value) && !is_int($value) && !is_float($value)) {
					continue;
				}
				$value = trim((string) $value);
				if ('' === $value || !$this->isSafeFormAction($value)) {
					continue;
				}
			} else {
				if (!is_string($value) && !is_int($value) && !is_float($value)) {
					continue;
				}
				$value = (string) $value;
			}
			$out .= ' '.$key.'="'.htmlspecialchars($value, ENT_QUOTES).'"';
		}

		return $out;
	}

	private function isSafeFormAction(string $action): bool
	{
		if (preg_match('/[\x00-\x1F\x7F]/', $action)) {
			return false;
		}
		if (1 === preg_match('/^([a-z][a-z0-9+.-]*):/i', $action, $match)) {
			return in_array(strtolower($match[1]), ['http', 'https'], true);
		}

		return true;
	}

	/**
	 * Render the user-editable arbitrary HTML attributes onto the block wrapper.
	 * Applies an allowlist/deny-list to prevent attribute-injection XSS: attribute
	 * names must be valid, `on*` event handlers and `style` are rejected, and
	 * `javascript:`/`data:` URL schemes are stripped from values.
	 *
	 * @param array<string,mixed> $element
	 */
	private function renderElementAttributes(array $element, ?BlockType $blockType = null): string
	{
		$attributes = $element['attributes'] ?? null;
		if (!is_array($attributes)) {
			return '';
		}
		$excludedNames = [];
		foreach ($blockType->contentFields ?? [] as $field) {
			if (($field['wrapperAttribute'] ?? false) === true && is_string($field['key'] ?? null)) {
				$excludedNames[] = strtolower($field['key']);
			}
		}

		$out = '';
		foreach ($attributes as $attribute) {
			if (!is_array($attribute)) {
				continue;
			}
			$name = (string) ($attribute['name'] ?? '');
			$value = (string) ($attribute['value'] ?? '');
			if (in_array(strtolower($name), $excludedNames, true) || '' === $name || !$this->isSafeAttributeName($name) || !$this->isSafeAttributeValue($value)) {
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
				'style' => $this->styleForOutput(
					is_array($node['style'] ?? null) ? $node['style'] : [],
					$blockType,
					$blockType->type,
					$this->hasResponsiveStyles($node, $blockType, $blockType->type),
				),
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
	 *
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

	/** @param array<string,mixed> $style */
	private function styleForOutput(array $style, ?BlockType $blockType, string $type, bool $responsive = false): string
	{
		if ($responsive) {
			return '';
		}
		$declarations = $this->styleDeclarations($style['base'] ?? [], $blockType, $type);
		$pairs = [];
		foreach ($declarations as $property => $value) {
			$pairs[] = $property.':'.$value.';';
		}

		return implode('', $pairs);
	}

	/**
	 * @param array<string,mixed>                                                            $node
	 * @param array<string,array<string,array{width:int,declarations:array<string,string>}>> $rules
	 */
	private function collectResponsiveStyles(array $node, ?BlockType $blockType, string $type, array &$rules): void
	{
		$blockId = $node['id'] ?? null;
		$style = $node['style'] ?? null;
		if (!is_string($blockId) || '' === $blockId || !is_array($style)) {
			return;
		}

		$blockClass = self::blockIdClass($blockId);
		$blockSelector = '.'.$blockClass.'.'.$blockClass;
		$selector = match ($type) {
			'container' => $blockSelector.' > .iikiti-container',
			'query' => $blockSelector.' .iikiti-query, '.$blockSelector.' .iikiti-query--empty',
			'icon' => $blockSelector.' > .iikiti-icon-block',
			'image' => $blockSelector.' .iikiti-image',
			default => $blockSelector,
		};

		$responsiveLayers = [];
		foreach (ResponsiveBreakpoints::WIDTHS as $breakpoint => $width) {
			$declarations = $this->styleDeclarations($style[$breakpoint] ?? [], $blockType, $type);
			if ([] !== $declarations) {
				$responsiveLayers[$breakpoint] = ['width' => $width, 'declarations' => $declarations];
			}
		}
		if ([] === $responsiveLayers) {
			return;
		}
		$baseDeclarations = $this->styleDeclarations($style['base'] ?? [], $blockType, $type);
		if ([] !== $baseDeclarations) {
			$rules[$selector]['base'] = ['width' => 0, 'declarations' => $baseDeclarations];
		}
		$rules[$selector] = array_replace($rules[$selector] ?? [], $responsiveLayers);
	}

	/** @param array<string,mixed> $node */
	private function hasResponsiveStyles(array $node, ?BlockType $blockType, string $type): bool
	{
		$blockId = $node['id'] ?? null;
		$style = $node['style'] ?? null;
		if (!is_string($blockId) || '' === $blockId || !is_array($style)) {
			return false;
		}
		foreach (ResponsiveBreakpoints::WIDTHS as $breakpoint => $width) {
			if ([] !== $this->styleDeclarations($style[$breakpoint] ?? [], $blockType, $type)) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,array<string,array{width:int,declarations:array<string,string>}>> $rules */
	private function responsiveStylesheet(array $rules): string
	{
		$css = '';
		foreach ($rules as $selector => $breakpoints) {
			foreach ($breakpoints as $breakpoint => $rule) {
				$declarations = [];
				foreach ($rule['declarations'] as $property => $value) {
					$declarations[] = $property.':'.$value;
				}
				$blockRule = $selector.'{'.implode(';', $declarations).'}';
				$css .= 'base' === $breakpoint ? $blockRule : '@media (min-width:'.$rule['width'].'px){'.$blockRule.'}';
			}
		}

		return '' === $css ? '' : '<style data-iikiti-responsive-styles>'.$css.'</style>';
	}

	/** @return array<string,string> */
	private function styleDeclarations(mixed $layer, ?BlockType $blockType, string $type): array
	{
		if (!is_array($layer)) {
			return [];
		}

		$schemaProperties = [];
		$styleFields = null === $blockType ? [] : $blockType->styleFields;
		foreach ($styleFields as $field) {
			$key = $field['key'] ?? null;
			if (!is_string($key)) {
				continue;
			}
			$property = is_string($field['cssProperty'] ?? null) ? $field['cssProperty'] : $this->cssProperty($key);
			if (1 === preg_match('/^[a-z][a-z0-9-]*$/', $property)) {
				$schemaProperties[$key] = $property;
			}
		}

		$declarations = [];
		foreach ($layer as $key => $value) {
			if (!is_string($key) || null === $value) {
				continue;
			}
			$property = $schemaProperties[$key] ?? $this->legacyCssProperty($key, $type);
			if (null === $property || 1 !== preg_match('/^[a-z][a-z0-9-]*$/', $property)) {
				continue;
			}
			$normalized = $this->normalizeCssValue($property, $value);
			if (null !== $normalized) {
				$declarations[$property] = $normalized;
			}
		}

		return $declarations;
	}

	private function legacyCssProperty(string $key, string $type): ?string
	{
		return match ($key) {
			'layout' => 'display',
			'align' => 'container' === $type ? 'justify-content' : 'text-align',
			'size' => 'icon' === $type ? 'font-size' : null,
			default => null,
		};
	}

	private function normalizeCssValue(string $property, mixed $value): ?string
	{
		if (is_int($value) || is_float($value)) {
			if (!is_finite((float) $value)) {
				return null;
			}
			if ('opacity' === $property && ($value < 0 || $value > 1)) {
				return null;
			}
			$unitless = in_array($property, ['opacity', 'font-weight', 'line-height', 'z-index'], true);

			return $unitless || 0.0 === (float) $value ? (string) $value : (string) $value.'px';
		}
		if (!is_string($value)) {
			return null;
		}

		$value = trim($value);
		if ('' === $value || strlen($value) > 256 || 1 === preg_match('/[;{}<>\\\\"\'\x00-\x1F\x7F]/', $value)) {
			return null;
		}
		if (1 === preg_match('/\\b(?:url|expression|var|attr)\\s*\\(/i', $value)) {
			return null;
		}
		if (in_array($property, ['color', 'background-color', 'border-color'], true)) {
			if (1 !== preg_match('/^(?:#[0-9a-f]{3,8}|[a-z]+|(?:rgb|rgba|hsl|hsla)\([0-9a-zA-Z.,%\/+\s-]+\))$/i', $value)) {
				return null;
			}
		} elseif (1 !== preg_match('/^[a-zA-Z0-9#.%(),\/:+\\s_-]+$/', $value)) {
			return null;
		}
		if ('aspect-ratio' === $property) {
			if ('original' === $value) {
				return null;
			}
			$value = preg_replace('/^(\\d+)\\s*:\\s*(\\d+)$/', '$1 / $2', $value) ?? $value;
		}
		if ('justify-content' === $property && 'start' === $value) {
			$value = 'flex-start';
		} elseif ('justify-content' === $property && 'end' === $value) {
			$value = 'flex-end';
		}
		if (in_array($property, self::CSS_LENGTH_PROPERTIES, true) && 1 === preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)$/', $value)) {
			$value .= 'px';
		}

		return $value;
	}

	private function cssProperty(string $key): string
	{
		$kebab = preg_replace('/([a-z])([A-Z])/', '$1-$2', $key);

		return strtolower((string) $kebab);
	}
}
