<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Attribute;

/**
 * Overrides the single-table inheritance discriminator value for a {@see \iikiti\CMS\Entity\DbObject}
 * subclass instead of using its lowercase short class name.
 *
 * Plugins should prefer a plugin-prefixed value (e.g. `my_plugin.widget`) to
 * avoid collisions across independent bundles.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class DbObjectDiscriminator
{
	public function __construct(
		public readonly string $value,
	) {
	}
}
