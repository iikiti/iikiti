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
	 */
	public function __construct(
		public readonly bool $editorMode,
		public readonly ?Site $site = null,
		public readonly ?DbObject $object = null,
		public readonly ?Request $request = null,
		public readonly array $dynamicBlocks = [],
	) {
	}

	public function withEditorMode(bool $editorMode): self
	{
		return new self($editorMode, $this->site, $this->object, $this->request, $this->dynamicBlocks);
	}
}
