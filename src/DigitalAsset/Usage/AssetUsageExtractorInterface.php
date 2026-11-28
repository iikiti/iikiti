<?php

declare(strict_types=1);

namespace iikiti\CMS\DigitalAsset\Usage;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extracts digital asset references (by numeric assetId or 64-hex publicToken)
 * from stored CMS JSON payloads (block trees, settings, site/application config,
 * or plugin-specific structures).
 */
#[AutoconfigureTag('iikiti.asset_usage_extractor')]
interface AssetUsageExtractorInterface
{
	/**
	 * @return list<array{assetId?: int, publicToken?: string, fieldPath: string}>
	 */
	public function extractFromValue(mixed $value, string $basePath): array;
}
