<?php

declare(strict_types=1);

namespace iikiti\CMS\Storage;

/**
 * Provides the 12 core digital asset MIME type definitions shipped with the CMS.
 */
final readonly class CoreAssetMimeTypeProvider implements AssetMimeTypeProviderInterface
{
	public const DEFAULT_MAX_BYTES = 26214400;

	/**
	 * @return list<AssetMimeTypeDefinition>
	 */
	public function getDefinitions(): array
	{
		return [
			new AssetMimeTypeDefinition('image/jpeg', 'jpg', 'image', self::DEFAULT_MAX_BYTES, true),
			new AssetMimeTypeDefinition('image/png', 'png', 'image', self::DEFAULT_MAX_BYTES, true),
			new AssetMimeTypeDefinition('image/gif', 'gif', 'image', self::DEFAULT_MAX_BYTES, true),
			new AssetMimeTypeDefinition('image/webp', 'webp', 'image', self::DEFAULT_MAX_BYTES, true),
			new AssetMimeTypeDefinition('image/avif', 'avif', 'image', self::DEFAULT_MAX_BYTES, true),
			new AssetMimeTypeDefinition('application/pdf', 'pdf', 'document', self::DEFAULT_MAX_BYTES, false),
			new AssetMimeTypeDefinition('text/plain', 'txt', 'text', self::DEFAULT_MAX_BYTES, false),
			new AssetMimeTypeDefinition('text/markdown', 'md', 'text', self::DEFAULT_MAX_BYTES, false),
			new AssetMimeTypeDefinition('text/csv', 'csv', 'text', self::DEFAULT_MAX_BYTES, false),
			new AssetMimeTypeDefinition('application/json', 'json', 'data', self::DEFAULT_MAX_BYTES, false),
			new AssetMimeTypeDefinition('application/xml', 'xml', 'data', self::DEFAULT_MAX_BYTES, false),
			new AssetMimeTypeDefinition('text/xml', 'xml', 'data', self::DEFAULT_MAX_BYTES, false),
		];
	}
}
