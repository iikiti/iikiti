<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\DigitalAssetRepository;
use iikiti\CMS\Workflow\Subject\WorkflowSubjectResolverInterface;

final class DigitalAssetWorkflowSubjectResolver implements WorkflowSubjectResolverInterface
{
	public function __construct(private readonly DigitalAssetRepository $assets)
	{
	}

	public function getKey(): string
	{
		return 'digital_asset';
	}

	public function resolve(string $subjectId): ?object
	{
		return $this->assets->find($subjectId);
	}

	public function isAllowedInSite(object $subject, Site $site): bool
	{
		if (!$subject instanceof AbstractDigitalAsset) {
			return false;
		}

		foreach ($subject->getAccessSites() as $accessSite) {
			if ($accessSite->getId() === $site->getId()) {
				return true;
			}
		}

		return false;
	}
}
