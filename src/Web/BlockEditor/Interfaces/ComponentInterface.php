<?php

namespace iikiti\CMS\Web\BlockEditor\Interfaces;

/**
 * Ensures block component classes implement the required methods.
 */
interface ComponentInterface
{
	public function getContainerList(): array;

	public function getSettingsFields(): array;
}
