<?php

declare(strict_types=1);

namespace iikiti\CMS\Storage;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point allowing core and plugins to register supported digital asset MIME types.
 */
#[AutoconfigureTag('iikiti.asset_mime_type_provider')]
interface AssetMimeTypeProviderInterface
{
	/**
	 * @return list<AssetMimeTypeDefinition>
	 */
	public function getDefinitions(): array;
}
