<?php

declare(strict_types=1);

namespace iikiti\CMS\Authentication\Mfa;

use iikiti\CMS\Workflow\Policy\WorkflowPolicyContext;
use iikiti\CMS\Workflow\Policy\WorkflowPolicyInterface;
use iikiti\CMS\Workflow\StepProvider\MfaStepProvider;

final class MfaProofWorkflowPolicy implements WorkflowPolicyInterface
{
	public const KEY = 'core.mfa_proof_gate';

	public function __construct(private readonly MfaProofPolicy $proofPolicy)
	{
	}

	public function getKey(): string
	{
		return self::KEY;
	}

	public function isAllowed(WorkflowPolicyContext $context): bool
	{
		if (null !== $context->instance || MfaStepProvider::WORKFLOW_NAME !== $context->workflowName) {
			return false;
		}
		if (!$context->targetFinal) {
			return true;
		}
		$requiredProofs = $context->workflowContext[MfaStepProvider::CONTEXT_REQUIRED_PROOFS] ?? null;
		if (!is_array($requiredProofs) || !array_is_list($requiredProofs)) {
			return false;
		}

		return $this->proofPolicy->isSatisfied($requiredProofs, $context->workflowContext);
	}
}
