<?php

namespace iikiti\CMS\Web\BlockEditor\Interfaces;

/**
 * Ensures block component classes implement the required methods.
 */
interface ComponentInterface
{
	/**
	 * @return array<string,mixed>
	 */
	public function getContainerList(): array;

	/**
	 * @return array<string,mixed>
	 */
	public function getSettingsFields(): array;
}
