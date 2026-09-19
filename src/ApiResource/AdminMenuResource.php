<?php

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use iikiti\CMS\State\Provider\AdminMenuProvider;

/**
 * Admin navigation menu resource.
 *
 * Exposes the aggregated admin menu structure from all registered
 * {@see \iikiti\CMS\Admin\AdminExtensionInterface} implementations.
 * Plugins contribute menu items through tagged services.
 */
#[ApiResource(
	operations: [
		new GetCollection(
			uriTemplate: '/admin/menu',
			name: 'admin_menu_list',
			provider: AdminMenuProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
	normalizationContext: ['groups' => ['menu:read']],
)]
class AdminMenuResource
{
	public function __construct(
		/** @var list<AdminMenuItem> */
		public array $items = [],
	) {
	}
}
