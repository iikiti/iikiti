<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

/**
 * Enforces that every block sits inside a container: only `container` nodes may
 * appear at the root of a region tree. Text, headings, media and every other
 * type must be nested under a container.
 */
final class RootContainerRule
{
	public const ROOT_TYPE = 'container';

	public static function isAllowedAtRoot(string $type): bool
	{
		return self::ROOT_TYPE === $type;
	}

	/**
	 * Returns the root-level nodes that violate the rule, keyed by region id.
	 *
	 * @param array<string,mixed> $regionTrees
	 *
	 * @return list<array{region:string,index:int,type:string}>
	 */
	public static function violations(array $regionTrees): array
	{
		$violations = [];
		foreach ($regionTrees as $regionId => $nodes) {
			if (!is_array($nodes)) {
				continue;
			}
			foreach ($nodes as $index => $node) {
				$type = is_array($node) ? (string) ($node['type'] ?? '') : '';
				if (!self::isAllowedAtRoot($type)) {
					$violations[] = ['region' => (string) $regionId, 'index' => (int) $index, 'type' => $type];
				}
			}
		}

		return $violations;
	}
}
