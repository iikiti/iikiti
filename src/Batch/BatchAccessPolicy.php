<?php

declare(strict_types=1);

namespace iikiti\CMS\Batch;

use iikiti\CMS\Entity\Batch\BatchJob;
use iikiti\CMS\Entity\Batch\BatchSchedule;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;

final class BatchAccessPolicy
{
	public function __construct(private readonly SiteRepository $sites, private readonly WorkflowAccessPolicy $workflowAccess)
	{
	}

	public function isGlobalAdmin(User $user): bool
	{
		return $this->workflowAccess->isGlobalAdmin($user);
	}

	public function canManageSite(User $user, int|string|null $siteId): bool
	{
		if (null === $siteId) {
			return false;
		}
		$site = $this->sites->find($siteId);

		return $site instanceof Site && $this->workflowAccess->canManageSite($user, $site);
	}

	public function canAccessJob(User $user, BatchJob $job): bool
	{
		return $this->isGlobalAdmin($user) || $this->canManageSite($user, $job->getSiteId());
	}

	public function canAccessSchedule(User $user, BatchSchedule $schedule): bool
	{
		return 'global' === $schedule->getScope()
			? $this->isGlobalAdmin($user)
			: $this->canManageSite($user, $schedule->getSiteId());
	}

	public function canManageScope(User $user, string $scope, int|string|null $siteId): bool
	{
		return 'global' === $scope ? $this->isGlobalAdmin($user) : $this->canManageSite($user, $siteId);
	}

	/** @return list<int|string> */
	public function getManageableSiteIds(User $user): array
	{
		$ids = [];
		foreach ($this->sites->findAll(['filterBySite' => false]) as $site) {
			if ($this->workflowAccess->canManageSite($user, $site) && null !== $site->getId()) {
				$ids[] = $site->getId();
			}
		}

		return $ids;
	}
}
