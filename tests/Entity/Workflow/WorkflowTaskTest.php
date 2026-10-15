<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity\Workflow;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Role;
use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Entity\Workflow\WorkflowTask;
use PHPUnit\Framework\TestCase;

final class WorkflowTaskTest extends TestCase
{
	public function testRoleTaskCanBeClaimedAndCompleted(): void
	{
		$instance = new WorkflowInstance(
			new WorkflowDefinitionVersion(
				new WorkflowDefinition('approval.review', 'Approval', 'site', '7'),
				1,
				['initialPlaces' => ['review']],
			),
			new Site(),
		);
		$task = new WorkflowTask($instance, 'review', 0, 'Review request', new Site(), assigneeRole: new Role('Site manager', 'ROLE_SITE_MANAGER'));

		$task->claim(new User());
		self::assertSame('claimed', $task->getStatus());
		self::assertNotNull($task->getClaimedBy());

		$task->complete();
		self::assertSame('completed', $task->getStatus());
	}

	public function testTaskRequiresExactlyOneAssignmentTarget(): void
	{
		$instance = new WorkflowInstance(
			new WorkflowDefinitionVersion(
				new WorkflowDefinition('approval.review', 'Approval', 'core', 'core'),
				1,
				['initialPlaces' => ['review']],
			),
		);

		$this->expectException(\InvalidArgumentException::class);
		new WorkflowTask($instance, 'review', 0, 'Review request');
	}
}
