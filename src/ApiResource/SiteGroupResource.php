<?php

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use iikiti\CMS\Entity\Object\SiteGroup;
use iikiti\CMS\State\Processor\SiteGroupProcessor;
use iikiti\CMS\State\Provider\SiteGroupProvider;

#[ApiResource(
	operations: [
		new GetCollection(
			uriTemplate: '/admin/site-groups',
			name: 'admin_site_groups_list',
			provider: SiteGroupProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Get(
			uriTemplate: '/admin/site-groups/{id}',
			name: 'admin_site_group_get',
			provider: SiteGroupProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Post(
			uriTemplate: '/admin/site-groups',
			name: 'admin_site_group_create',
			processor: SiteGroupProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Put(
			uriTemplate: '/admin/site-groups/{id}',
			name: 'admin_site_group_update',
			processor: SiteGroupProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Delete(
			uriTemplate: '/admin/site-groups/{id}',
			name: 'admin_site_group_delete',
			processor: SiteGroupProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
)]
class SiteGroupResource
{
	public function __construct(
		#[ApiProperty(identifier: true)]
		public ?int $id = null,
		public string $name = '',
		public string $label = '',
		public ?string $description = null,
		public bool $isSystem = false,
		/** @var array<int,string> */
		public array $siteIds = [],
	) {
	}

	public static function fromEntity(SiteGroup $group): self
	{
		return new self(
			id: $group->getId(),
			name: $group->getName() ?? '',
			label: $group->getLabel() ?? '',
			description: $group->getDescription(),
			isSystem: $group->isSystem(),
			siteIds: $group->getSiteIds(),
		);
	}
}
