<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use Symfony\Component\HttpFoundation\Request;

/**
 * Read-only contextual state for block rendering.
 */
final class BlockRenderContext
{
	/**
	 * @param array<string,list<array<string,mixed>>> $dynamicBlocks Per-object
	 *                                                               dynamic-block children keyed by dynamic-block id (for `dynamic` blocks)
	 * @param bool $canEdit Whether the current user is logged in and authorised to
	 *                      edit the current page/template (independent of `?edit`),
	 *                      used to gate editor-only placeholder content.
	 * @param mixed $item The query result row currently being rendered (per-result
	 *                    template rendering of `query` block children); null when
	 *                    no per-item context is active.
	 * @param int $queryDepth How many `query` per-result loops we are inside —
	 *                        guards against nested `query` blocks multiplying
	 *                        per-row execution and rendering (query-in-query
	 *                        falls back to the block's legacy rendering).
	 */
	public function __construct(
		public readonly bool $editorMode,
		public readonly bool $canEdit = false,
		public readonly ?Site $site = null,
		public readonly ?DbObject $object = null,
		public readonly ?Request $request = null,
		public readonly array $dynamicBlocks = [],
		public readonly mixed $item = null,
		public readonly int $queryDepth = 0,
	) {
	}

	public function withEditorMode(bool $editorMode): self
	{
		return new self($editorMode, $this->canEdit, $this->site, $this->object, $this->request, $this->dynamicBlocks, $this->item, $this->queryDepth);
	}

	/**
	 * Per-result rendering context for `query` block children: every descendant
	 * of the query block is re-rendered once per query result, with the result
	 * row available as `item` (and the row's field bindings resolved against it).
	 */
	public function withItem(mixed $item): self
	{
		return new self($this->editorMode, $this->canEdit, $this->site, $this->object, $this->request, $this->dynamicBlocks, $item, $this->queryDepth + 1);
	}
}
