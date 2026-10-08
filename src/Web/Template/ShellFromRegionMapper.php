<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template;

/**
 * Maps a legacy template's non-main regions (and their stored block trees) to
 * shell records. Used by the shell-conversion migration; kept out of the
 * migration class so it can be unit tested.
 */
final class ShellFromRegionMapper
{
	/** Legacy region role => shell role. `main` is never converted. */
	private const ROLE_MAP = [
		'header' => 'header',
		'footer' => 'footer',
		'sidebar' => 'aside',
		'dialog' => 'dialog',
	];

	/**
	 * @param list<array<string,mixed>>               $regions
	 * @param array<string,list<array<string,mixed>>> $blocks
	 *
	 * @return list<array{role:string,name:string,priority:int,blocks:list<array<string,mixed>>}>
	 */
	public static function shellsFromRegions(array $regions, array $blocks): array
	{
		$shells = [];
		$priority = 0;
		foreach ($regions as $region) {
			$id = (string) ($region['id'] ?? '');
			$role = self::ROLE_MAP[(string) ($region['role'] ?? '')] ?? null;
			if (null === $role) {
				continue;
			}
			$regionBlocks = $blocks[$id] ?? [];
			if (!is_array($regionBlocks) || [] === $regionBlocks) {
				continue;
			}
			$shells[] = [
				'role' => $role,
				'name' => (string) ($region['name'] ?? $id),
				'priority' => $priority++,
				'blocks' => array_values($regionBlocks),
			];
		}

		return $shells;
	}
}
