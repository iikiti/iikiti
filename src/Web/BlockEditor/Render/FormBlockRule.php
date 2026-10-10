<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

final class FormBlockRule
{
	private const FORM_CHILD_TYPES = ['input', 'textarea', 'select', 'range', 'checkbox', 'radio', 'button', 'fieldset'];
	private const FIELDSET_CHILD_TYPES = ['legend', 'input', 'textarea', 'select', 'range', 'checkbox', 'radio', 'button'];

	public static function firstViolation(mixed $regionTrees): ?FormBlockViolation
	{
		if (!is_array($regionTrees)) {
			return null;
		}
		foreach ($regionTrees as $regionId => $nodes) {
			if (!is_array($nodes)) {
				continue;
			}
			foreach ($nodes as $index => $node) {
				$violation = self::inspectNode($node, (string) $regionId, (int) $index, null, false);
				if (null !== $violation) {
					return $violation;
				}
			}
		}

		return null;
	}

	private static function inspectNode(mixed $node, string $region, int $rootIndex, ?string $parentType, bool $insideForm): ?FormBlockViolation
	{
		if (!is_array($node)) {
			return null;
		}
		$type = is_string($node['type'] ?? null) ? $node['type'] : '';
		if ('legend' === $type && 'fieldset' !== $parentType) {
			return new FormBlockViolation($region, $rootIndex, $type, 'legend_parent');
		}
		if ('form' === $type && $insideForm) {
			return new FormBlockViolation($region, $rootIndex, $type, 'nested_form');
		}
		if ('form' === $parentType && !in_array($type, self::FORM_CHILD_TYPES, true)) {
			return new FormBlockViolation($region, $rootIndex, $type, 'form_child');
		}
		if ('fieldset' === $parentType && !in_array($type, self::FIELDSET_CHILD_TYPES, true)) {
			return new FormBlockViolation($region, $rootIndex, $type, 'fieldset_child');
		}

		$children = $node['children'] ?? [];
		if (!is_array($children)) {
			return null;
		}
		$legendCount = 0;
		$position = 0;
		foreach ($children as $child) {
			$childType = is_array($child) && is_string($child['type'] ?? null) ? $child['type'] : '';
			if ('fieldset' === $type && 'legend' === $childType) {
				++$legendCount;
				if ($legendCount > 1) {
					return new FormBlockViolation($region, $rootIndex, $childType, 'duplicate_legend');
				}
				if (0 !== $position) {
					return new FormBlockViolation($region, $rootIndex, $childType, 'legend_position');
				}
			}
			$violation = self::inspectNode($child, $region, $rootIndex, $type, $insideForm || 'form' === $type);
			if (null !== $violation) {
				return $violation;
			}
			++$position;
		}

		return null;
	}
}
