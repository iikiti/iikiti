<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\BlockType;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contract for objects that provide block types to the editor.
 *
 * Plugins (and the core) implement this and are auto-tagged with
 * `iikiti.cms.block_type` (via the attribute on this interface), so the
 * {@see BlockTypeRegistry} can collect them without explicit service registration.
 */
#[AutoconfigureTag('iikiti.cms.block_type')]
interface BlockTypeInterface
{
	/**
	 * @return list<BlockType>
	 */
	public function getBlockTypes(): array;
}
