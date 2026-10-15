<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Task;

use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Role;

final readonly class WorkflowTaskAssignment
{
	public function __construct(public ?User $user = null, public ?Role $role = null)
	{
		if ((null === $user) === (null === $role)) {
			throw new \InvalidArgumentException('Workflow task assignments target exactly one user or role.');
		}
	}
}
