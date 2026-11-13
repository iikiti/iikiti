<?php

namespace iikiti\CMS\Plugin;

/** Matches declared action claims without deciding whether overlap is incompatible. */
final class PluginActionMatcher
{
	/**
	 * @param iterable<PluginManifest> $others
	 *
	 * @return list<PluginActionConflict>
	 */
	public function findConflicts(PluginManifest $candidate, iterable $others): array
	{
		$conflicts = [];
		foreach ($others as $other) {
			if ($candidate->slug === $other->slug) {
				continue;
			}

			foreach (array_intersect($candidate->actionConflicts, $other->actions) as $actionId) {
				$conflicts[$other->slug.'|'.$actionId] = new PluginActionConflict($other->slug, $actionId);
			}
			foreach (array_intersect($other->actionConflicts, $candidate->actions) as $actionId) {
				$conflicts[$other->slug.'|'.$actionId] = new PluginActionConflict($other->slug, $actionId);
			}
		}

		return array_values($conflicts);
	}

	/**
	 * @param iterable<PluginManifest> $plugins
	 *
	 * @return list<PluginManifest>
	 */
	public function findClaimants(iterable $plugins, string $actionId): array
	{
		$claimants = [];
		foreach ($plugins as $plugin) {
			if (in_array($actionId, $plugin->actions, true)) {
				$claimants[] = $plugin;
			}
		}

		return $claimants;
	}
}
