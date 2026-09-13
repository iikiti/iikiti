<?php

namespace iikiti\CMS\Cache;

/**
 * Describes a caching strategy for enumeration by plugins and admin UIs.
 */
class StrategyMetadata
{
	/**
	 * @param array<int,string> $capabilities
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $label,
		public readonly string $description = '',
		public readonly array $capabilities = [],
	) {
	}
}
