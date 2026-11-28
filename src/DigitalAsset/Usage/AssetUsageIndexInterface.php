<?php

declare(strict_types=1);

namespace iikiti\CMS\DigitalAsset\Usage;

/**
 * Query and indexing contract for "Where Used" digital asset reference tracking.
 */
interface AssetUsageIndexInterface
{
	/**
	 * Returns all indexed references to the given digital asset, enriched with
	 * context titles and admin edit URLs.
	 *
	 * @return list<AssetUsageReference>
	 */
	public function findUsage(int|string $assetId): array;

	/**
	 * Batch-counts usage references for multiple asset IDs.
	 *
	 * @param list<int|string> $assetIds
	 *
	 * @return array<string, int>
	 */
	public function countUsageByAssetIds(array $assetIds): array;

	/**
	 * Re-extracts and persists all asset usages for a single context entity.
	 */
	public function reindexContext(string $contextType, int|string $contextId): void;

	/**
	 * Rebuilds the entire `digital_asset_usages` projection across all templates,
	 * pages, sites, and applications. Returns the total number of indexed contexts.
	 */
	public function rebuildAll(): int;
}
