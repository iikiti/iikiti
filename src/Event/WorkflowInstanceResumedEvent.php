<?php

declare(strict_types=1);

namespace iikiti\CMS\Event;

use iikiti\CMS\Entity\Workflow\WorkflowInstance;

final readonly class WorkflowInstanceResumedEvent
{
	public function __construct(public WorkflowInstance $instance)
	{
	}
}
