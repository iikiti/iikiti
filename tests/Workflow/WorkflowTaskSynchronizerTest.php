<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Workflow;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Role;
use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Entity\Workflow\WorkflowTask;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use iikiti\CMS\Workflow\Runtime\AppliedWorkflowTransition;
use iikiti\CMS\Workflow\Runtime\WorkflowTransitionResult;
use iikiti\CMS\Workflow\Task\WorkflowTaskAssignmentRegistry;
use iikiti\CMS\Workflow\Task\WorkflowTaskSynchronizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

final class WorkflowTaskSynchronizerTest extends TestCase
{
	public function testCreatesRoleTaskOnEntryAndCompletesItWhenPlaceIsLeft(): void
	{
		$site = new Site();
		$instance = $this->createInstance($site);
		$role = new Role('Site manager', 'ROLE_SITE_MANAGER');
		$roleRepository = $this->createStub(EntityRepository::class);
		$roleRepository->method('findOneBy')->willReturn($role);
		$createdTasks = [];
		$taskRepository = $this->createStub(EntityRepository::class);
		$taskRepository->method('findBy')->willReturnCallback(static function () use (&$createdTasks): array {
			return $createdTasks;
		});
		$entityManager = $this->createMock(EntityManagerInterface::class);
		$entityManager->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => WorkflowTask::class === $class ? $taskRepository : $roleRepository);
		$entityManager->expects(self::once())->method('persist')->willReturnCallback(static function (WorkflowTask $task) use (&$createdTasks): void {
			$createdTasks[] = $task;
		});
		$synchronizer = new WorkflowTaskSynchronizer(
			$entityManager,
			new WorkflowGraphCompiler(),
			new WorkflowTaskAssignmentRegistry(),
			new WorkflowAccessPolicy(
				new DigitalAssetAccessPolicy($this->createStub(SiteRepository::class), $this->createStub(RoleHierarchyInterface::class)),
				$this->createStub(RoleHierarchyInterface::class),
			),
		);

		self::assertSame(1, $synchronizer->createInitialTasks($instance));
		self::assertCount(1, $createdTasks);
		self::assertSame('ROLE_SITE_MANAGER', $createdTasks[0]->getAssigneeRoleKey());
		self::assertSame('open', $createdTasks[0]->getStatus());

		$synchronizer->synchronize($instance, new WorkflowTransitionResult(
			['review' => 1],
			['complete' => 1],
			[new AppliedWorkflowTransition('finish', ['review'], ['complete'], [], ['review' => 1], ['complete' => 1])],
			true,
		));

		self::assertSame('completed', $createdTasks[0]->getStatus());
	}

	private function createInstance(Site $site): WorkflowInstance
	{
		return new WorkflowInstance(new WorkflowDefinitionVersion(
			new WorkflowDefinition('site.review', 'Review', 'site', '7'),
			1,
			[
				'places' => [
					['id' => 'review', 'metadata' => ['humanTask' => ['roleKey' => 'ROLE_SITE_MANAGER']]],
					'complete',
				],
				'initialPlaces' => ['review'],
				'finalPlaces' => ['complete'],
				'transitions' => [['name' => 'finish', 'from' => 'review', 'to' => 'complete']],
				'metadata' => ['policyKey' => 'core.site_workflow'],
			],
		), $site);
	}
}
