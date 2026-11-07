<?php

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use iikiti\CMS\State\Processor\PluginConfigurationProcessor;

/** Narrow administrative actions for per-site plugin configuration. */
#[ApiResource(
	operations: [
		new Post(
			uriTemplate: '/admin/plugins/configure',
			name: 'admin_plugin_configure',
			processor: PluginConfigurationProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Post(
			uriTemplate: '/admin/plugins/migrate-configuration',
			name: 'admin_plugin_migrate_configuration',
			processor: PluginConfigurationProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
)]
class PluginConfigurationOperation
{
	/**
	 * @param list<string>        $set
	 * @param list<string>        $unset
	 * @param array<string,mixed> $configuration
	 * @param array<string,mixed> $result
	 */
	public function __construct(
		public string $slug = '',
		public ?string $siteId = null,
		public array $set = [],
		public array $unset = [],
		public array $configuration = [],
		public bool $success = false,
		public ?string $message = null,
		public array $result = [],
	) {
	}
}
