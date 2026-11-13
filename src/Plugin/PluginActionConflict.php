<?php

namespace iikiti\CMS\Plugin;

final readonly class PluginActionConflict
{
	public function __construct(
		public string $pluginSlug,
		public string $actionId,
	) {
	}

	/** @return array{plugin:string,action:string} */
	public function toArray(): array
	{
		return ['plugin' => $this->pluginSlug, 'action' => $this->actionId];
	}
}
