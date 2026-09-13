<?php

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use iikiti\CMS\State\Processor\PluginProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command model for plugin management operations.
 *
 * Each POST operation targets a distinct action; {@see PluginProcessor} dispatches
 * on the operation name and populates the result fields.
 */
#[ApiResource(
	operations: [
		new Post(
			uriTemplate: '/admin/plugins/install',
			name: 'admin_plugin_install',
			processor: PluginProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Post(
			uriTemplate: '/admin/plugins/update',
			name: 'admin_plugin_update',
			processor: PluginProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Post(
			uriTemplate: '/admin/plugins/remove',
			name: 'admin_plugin_remove',
			processor: PluginProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Post(
			uriTemplate: '/admin/plugins/enable',
			name: 'admin_plugin_enable',
			processor: PluginProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Post(
			uriTemplate: '/admin/plugins/disable',
			name: 'admin_plugin_disable',
			processor: PluginProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Post(
			uriTemplate: '/admin/plugins/verify',
			name: 'admin_plugin_verify',
			processor: PluginProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
)]
class PluginOperation
{
	/**
	 * @param list<string>        $sites  site ids to target; empty means all sites
	 * @param array<string,mixed> $result
	 */
	public function __construct(
		#[Assert\NotBlank(message: 'A plugin slug is required.')]
		public string $slug = '',

		public ?string $version = null,

		/** @var list<string> */
		public array $sites = [],

		public ?string $storeUrl = null,

		public bool $dryRun = false,

		public bool $success = false,
		public ?string $message = null,
		public array $result = [],
	) {
	}
}
