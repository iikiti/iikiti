<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Workflow\Policy;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

final class WorkflowAccessPolicyTest extends TestCase
{
	public function testSiteManagerCanManageOnlyDefinitionsOwnedByTheirSite(): void
	{
		$site = $this->createStub(Site::class);
		$site->method('getId')->willReturn(7);
		$user = $this->createStub(User::class);
		$user->method('getGlobalRoles')->willReturn([]);
		$user->method('getRegistrationRoles')->willReturn(['site_manager' => \iikiti\CMS\Enum\UserRoleEnum::from('ROLE_SITE_MANAGER')]);
		$hierarchy = $this->createStub(RoleHierarchyInterface::class);
		$hierarchy->method('getReachableRoleNames')->willReturn(['ROLE_SITE_MANAGER']);
		$policy = new WorkflowAccessPolicy(new DigitalAssetAccessPolicy($this->createStub(SiteRepository::class), $hierarchy), $hierarchy);
		$siteDefinition = new WorkflowDefinition('site.review', 'Review', 'site', '7');
		$coreDefinition = new WorkflowDefinition('core.review', 'Review', 'core', 'core');
		$pluginDefinition = new WorkflowDefinition('plugin.review', 'Review', 'plugin', 'vendor/plugin');

		self::assertTrue($policy->canManageSite($user, $site));
		self::assertTrue($policy->canManageDefinition($user, $siteDefinition, $site));
		self::assertFalse($policy->canManageDefinition($user, $coreDefinition, $site));
		self::assertFalse($policy->canManageDefinition($user, $pluginDefinition, $site));
	}
}
