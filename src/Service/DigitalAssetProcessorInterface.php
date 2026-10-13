<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('iikiti.digital_asset_processor')]
interface DigitalAssetProcessorInterface
{
	public function getKey(): string;

	public function supportsMimePattern(string $mimePattern): bool;

	public function process(AbstractDigitalAsset $asset, AssetWorkflowDefinition $workflow): void;
}
