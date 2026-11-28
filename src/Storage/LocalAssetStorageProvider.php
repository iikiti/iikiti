<?php

declare(strict_types=1);

namespace iikiti\CMS\Storage;

/**
 * Registers the built-in local filesystem driver in {@see AssetStorageRegistry}.
 */
final readonly class LocalAssetStorageProvider implements AssetStorageProviderInterface
{
	public function __construct(
		private LocalAssetStorage $storage,
	) {
	}

	public function getDriverName(): string
	{
		return $this->storage->getDriverName();
	}

	public function createStorage(): AssetStorageInterface
	{
		return $this->storage;
	}

	public function isConfigured(): bool
	{
		return true;
	}
}
