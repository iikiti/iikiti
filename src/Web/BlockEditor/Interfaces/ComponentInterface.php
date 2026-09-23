<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Interfaces;

/**
 * Editor-facing block metadata contract (registry-backed).
 *
 * Prefer {@see \iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry} for new
 * code; this interface is the stable facade used by the editor pipeline.
 */
interface ComponentInterface
{
	/**
	 * Block types that accept children (containers), with their allowed children.
	 *
	 * @return list<array{type:string, label:string, allowed_child_types:list<string>|null}>
	 */
	public function getContainerList(): array;

	/**
	 * Merged content + style field schema for a block type.
	 *
	 * @return list<array<string,mixed>>|null
	 */
	public function getSettingsFields(string $blockType): ?array;
}
