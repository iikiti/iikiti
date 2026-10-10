<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\BlockType;

/**
 * Describes a single block type (e.g. "container", "image", "query") that the
 * editor can render and edit.
 *
 * Field schemas (content + style) are simple arrays so they can be stored as JSON on
 * the {@see \iikiti\CMS\Entity\Object\Template} and consumed by both the server
 * (Twig rendering / validation) and the Svelte editor (schema-driven inputs).
 *
 * A schema is a list of field definitions:
 * `[{ key, label, type, required?, options?, default?, ... }]`. The editor and the
 * style inspector render controls from these.
 */
final class BlockType
{
	/**
	 * @param list<string>              $allowedChildTypes Block type ids that may be nested here;
	 *                                                     empty = any block type allowed; `null` = no children
	 * @param list<array<string,mixed>> $contentFields
	 * @param list<array<string,mixed>> $styleFields
	 * @param list<array<string,mixed>> $elementFields     Element-level fields (id, css class, etc.) shown in
	 *                                                     the settings sidebar's "Element" tab
	 * @param array<string,mixed>       $defaults          Default content and/or style for new blocks
	 * @param string                    $source            Provider/plugin slug used to attribute the type in the
	 *                                                     block-widget enumeration index
	 * @param string                    $wrapperTag        HTML tag for the renderer's outer block wrapper
	 *                                                     (`div` by default; `span` for inline-only types so
	 *                                                     they stay valid inside e.g. `<h2>` wrappers)
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $label,
		public readonly string $category,
		public readonly bool $acceptsChildren = false,
		public readonly ?array $allowedChildTypes = null,
		public readonly array $contentFields = [],
		public readonly array $styleFields = [],
		public readonly ?string $renderTemplate = null,
		public readonly ?string $editorComponent = null,
		public readonly array $defaults = [],
		public readonly array $elementFields = [],
		public readonly string $source = 'core',
		public readonly string $wrapperTag = 'div',
		public readonly bool $childrenInWrapper = false,
	) {
	}

	/**
	 * Block types a child of this container may be. When `allowedChildTypes` is null,
	 * children are not accepted at all; when `[]` (empty), any child type is allowed.
	 *
	 * @return list<string>|null
	 */
	public function childTypes(): ?array
	{
		return $this->allowedChildTypes;
	}
}
