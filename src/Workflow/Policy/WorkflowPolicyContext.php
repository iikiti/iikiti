<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Policy;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Workflow\RuntimeWorkflowInterface;
use Symfony\Component\Workflow\Transition;

final readonly class WorkflowPolicyContext
{
	/** @param array<string,mixed> $workflowContext */
	public function __construct(
		public ?WorkflowDefinitionVersion $definitionVersion = null,
		public ?WorkflowInstance $instance = null,
		public ?RuntimeWorkflowInterface $interactiveWorkflow = null,
		public ?Site $site = null,
		public ?User $actor = null,
		public bool $fullyAuthenticated = false,
		public ?object $subject = null,
		public ?Transition $transition = null,
		public bool $automatic = false,
		public bool $targetFinal = false,
		public string $workflowName = '',
		public array $workflowContext = [],
	) {
	}
}
