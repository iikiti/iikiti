<?php

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use iikiti\CMS\State\Provider\PluginProvider;

/**
 * Read model describing an installed plugin for the admin API.
 */
#[ApiResource(
	operations: [
		new GetCollection(
			uriTemplate: '/admin/plugins',
			name: 'admin_plugins_list',
			provider: PluginProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Get(
			uriTemplate: '/admin/plugins/{slug}',
			name: 'admin_plugin_get',
			provider: PluginProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
)]
class PluginInfo
{
	/**
	 * @param list<string>        $activeSites
	 * @param array<string,mixed> $capabilities
	 */
	public function __construct(
		public string $slug = '',
		public string $name = '',
		public string $version = '',
		public string $edition = 'standard',
		public string $source = 'manual',
		public string $state = 'published',
		public bool $installed = true,
		public bool $active = false,
		public array $activeSites = [],
		public ?string $description = null,
		public array $capabilities = [],
	) {
	}
}
