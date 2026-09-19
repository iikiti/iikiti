<?php

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\State\Processor\SearchIndexProcessor;
use iikiti\CMS\Search\State\Provider\SearchIndexProvider;

/**
 * Search index configuration resource for the admin API.
 *
 * Provides CRUD operations on {@see SearchIndex} entities with protection
 * for system-locked configurations.
 */
#[ApiResource(
	operations: [
		new GetCollection(
			uriTemplate: '/admin/search/indexes',
			name: 'admin_search_indexes_list',
			provider: SearchIndexProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Get(
			uriTemplate: '/admin/search/indexes/{id}',
			name: 'admin_search_index_get',
			provider: SearchIndexProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Post(
			uriTemplate: '/admin/search/indexes',
			name: 'admin_search_index_create',
			processor: SearchIndexProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Put(
			uriTemplate: '/admin/search/indexes/{id}',
			name: 'admin_search_index_update',
			processor: SearchIndexProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Delete(
			uriTemplate: '/admin/search/indexes/{id}',
			name: 'admin_search_index_delete',
			processor: SearchIndexProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
)]
class SearchIndexResource
{
	/**
	 * @param list<array{name:string, label:string, source_type:string, source:string, weight:int}> $fields
	 */
	public function __construct(
		#[ApiProperty(identifier: true)]
		public ?int $id = null,
		public string $slug = '',
		public string $name = '',
		public string $type = 'custom',
		public string $engine = 'postgresql',
		public string $language = 'english',
		public ?string $description = null,
		public bool $systemLocked = false,
		public bool $isEnabled = true,
		public ?int $siteId = null,
		/** @var array<string,mixed> */
		public array $options = [],
		/** @var list<array{name:string, label:string, source_type:string, source:string, weight:int}> */
		public array $fields = [],
	) {
	}

	public static function fromEntity(SearchIndex $index): self
	{
		$fields = [];
		foreach ($index->getFields() as $field) {
			$fields[] = [
				'name' => $field->getName(),
				'label' => $field->getName(),
				'source_type' => $field->getSourceType()->value,
				'source' => $field->getSource(),
				'weight' => $field->getWeight(),
			];
		}

		return new self(
			id: $index->getId(),
			slug: $index->getSlug(),
			name: $index->getName(),
			type: $index->getType()->value,
			engine: $index->getEngine(),
			language: $index->getLanguage(),
			description: $index->getDescription(),
			systemLocked: $index->isSystemLocked(),
			isEnabled: $index->isEnabled(),
			siteId: $index->getSiteId(),
			options: $index->getOptions(),
			fields: $fields,
		);
	}
}
