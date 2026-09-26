<?php

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use iikiti\CMS\Search\State\Processor\SearchActionProcessor;
use iikiti\CMS\Search\State\Provider\SearchActionProvider;

/**
 * Action resource for search index operations (create, drop, rebuild)
 * and metadata endpoints (engines, object types, public filters).
 *
 * These are command-style operations, not entity-backed CRUD, following
 * the PluginOperation pattern.
 */
#[ApiResource(
	operations: [
		new Post(
			uriTemplate: '/admin/search/indexes/{id}/create',
			name: 'admin_search_index_create_table',
			processor: SearchActionProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Post(
			uriTemplate: '/admin/search/indexes/{id}/drop',
			name: 'admin_search_index_drop_table',
			processor: SearchActionProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Post(
			uriTemplate: '/admin/search/indexes/{id}/rebuild',
			name: 'admin_search_index_rebuild_table',
			processor: SearchActionProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Post(
			uriTemplate: '/admin/search/rebuild-all',
			name: 'admin_search_rebuild_all',
			processor: SearchActionProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new GetCollection(
			uriTemplate: '/admin/search/engines',
			name: 'admin_search_engines',
			provider: SearchActionProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new GetCollection(
			uriTemplate: '/admin/search/object-types',
			name: 'admin_search_object_types',
			provider: SearchActionProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new GetCollection(
			uriTemplate: '/admin/search/public-filters/{slug}',
			name: 'admin_search_public_filters',
			provider: SearchActionProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
)]
class SearchActionResource
{
	/**
	 * @param array<string,mixed>|null $result
	 */
	public function __construct(
		public ?string $action = null,
		public ?string $status = null,
		public ?string $message = null,
		public ?array $result = null,
		public ?string $indexSlug = null,
	) {
	}
}
