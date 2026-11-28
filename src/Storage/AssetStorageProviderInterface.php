<?php

declare(strict_types=1);

namespace iikiti\CMS\Storage;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point for pluggable digital asset storage backends (e.g. local, S3, GCS, Azure).
 */
#[AutoconfigureTag('iikiti.asset_storage_provider')]
interface AssetStorageProviderInterface
{
	public function getDriverName(): string;

	public function createStorage(): AssetStorageInterface;

	public function isConfigured(): bool;
}
