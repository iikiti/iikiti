<?php

namespace iikiti\CMS\Tests\Search\Api;

use ApiPlatform\Metadata\ApiResource;
use iikiti\CMS\ApiResource\SearchActionResource;
use PHPUnit\Framework\TestCase;

final class SearchActionResourceTest extends TestCase
{
	public function testSearchMaintenanceActionsAreAvailableAsAdminApiOperations(): void
	{
		$attributes = (new \ReflectionClass(SearchActionResource::class))->getAttributes(ApiResource::class);
		self::assertCount(1, $attributes);

		$resource = $attributes[0]->newInstance();
		$operations = [];
		foreach ($resource->getOperations() as $operation) {
			$operations[$operation->getName()] = $operation->getUriTemplate();
		}

		self::assertSame('/admin/search/rebuild-all', $operations['admin_search_rebuild_all']);
		self::assertSame('/admin/search/config/init', $operations['admin_search_config_init']);
	}
}
