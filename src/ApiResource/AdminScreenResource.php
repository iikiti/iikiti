<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use iikiti\CMS\State\Provider\AdminScreenProvider;

/**
 * Admin screen resource — the dynamic route manifest for the admin SPA.
 *
 * Exposes the aggregated list of admin screens from all registered
 * {@see \iikiti\CMS\Admin\AdminExtensionInterface} implementations. The SPA
 * fetches this endpoint on boot to build its route table, resolving generic
 * screens (list/detail/form) via core components and custom screens via
 * on-demand plugin bundle imports.
 */
#[ApiResource(
	operations: [
		new GetCollection(
			uriTemplate: '/admin/screens',
			name: 'admin_screens_list',
			provider: AdminScreenProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
)]
class AdminScreenResource
{
	/**
	 * @param list<AdminScreen> $items
	 */
	public function __construct(
		public array $items = [],
	) {
	}
}
