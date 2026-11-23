<?php

declare(strict_types=1);

namespace iikiti\CMS\Plugin\Attribute;

/**
 * Gates a controller class, controller method, or API Platform resource on an
 * active plugin. Requests when the plugin is inactive fail closed.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RequiresPlugin
{
	public function __construct(
		public readonly string $slug,
		public readonly ?string $scope = null,
		public readonly int $statusCode = 404,
	) {
	}
}
