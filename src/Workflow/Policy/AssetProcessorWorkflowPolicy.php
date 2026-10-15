<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Policy;

use iikiti\CMS\Entity\AbstractDigitalAsset;

final class AssetProcessorWorkflowPolicy implements WorkflowPolicyInterface
{
	public const KEY = 'core.asset_processor';

	public function getKey(): string
	{
		return self::KEY;
	}

	public function isAllowed(WorkflowPolicyContext $context): bool
	{
		$version = $context->definitionVersion;
		if (null === $version) {
			return false;
		}
		$processor = $version->getGraph()['metadata']['assetProcessor'] ?? null;

		return null === $context->actor &&
			'digital_asset' === $context->instance?->getSubjectType() &&
			$context->subject instanceof AbstractDigitalAsset &&
			is_array($processor) &&
			is_string($processor['processorKey'] ?? null);
	}
}
