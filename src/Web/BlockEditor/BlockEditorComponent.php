<?php

namespace iikiti\CMS\Web\BlockEditor;

use iikiti\CMS\Web\BlockEditor\Interfaces\ComponentInterface;
use Override;

/**
 * Manages block editor components.
 */
class BlockEditorComponent implements ComponentInterface
{
	#[Override]
	public function getContainerList(): array
	{
		// TODO: Implement getContainerList() method.
		return [];
	}

	#[Override]
	public function getSettingsFields(): array
	{
		// TODO: Implement getSettingsFields() method.
		return [];
	}
}
