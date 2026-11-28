<?php

declare(strict_types=1);

namespace iikiti\Extension\StorageS3\Registry;

use iikiti\CMS\Registry\AbstractRouteContextContributor;

/**
 * Classifies S3 object storage admin endpoints as application-level settings
 * routes in the route context classifier.
 */
final class StorageS3RouteContextContributor extends AbstractRouteContextContributor
{
	public function getApplicationAdminSettingsPathPatterns(): array
	{
		return [
			'^/api/admin/plugins/iikiti-storage-s3/(?:config|test)$',
		];
	}
}
