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
	 * @param array<string,mixed>       $defaults          Default content and/or style for new blocks
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
