<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Save-time structural validator for block editor trees.
 */
#[AutoconfigureTag('iikiti.cms.block_tree_validator')]
interface BlockTreeValidatorInterface
{
	/**
	 * Return the first structural violation in `$tree`, or `null` when valid.
	 *
	 * @param array<string,list<array<string,mixed>>> $tree
	 */
	public function validate(array $tree): ?BlockTreeViolation;
}
