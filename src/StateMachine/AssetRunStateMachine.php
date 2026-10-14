<?php

declare(strict_types=1);

namespace iikiti\CMS\StateMachine;

use iikiti\CMS\Entity\AssetWorkflowRun;
use Symfony\Component\Workflow\Attribute\AsWorkflow;
use Symfony\Component\Workflow\Attribute\Transition;
use Symfony\Component\Workflow\WorkflowTrait;

/**
 * Runtime status machine of a single digital-asset workflow run
 * (pending/running/terminal states) mirroring AssetWorkflowRun::$status.
 */
#[AsWorkflow(supports: AssetWorkflowRun::class, markingProperty: 'status')]
final class AssetRunStateMachine
{
	use WorkflowTrait;

	/**
	 * Kept reachable from every open place: a re-delivered processing message
	 * must be able to retry a run that was interrupted while running, or to
	 * revisit one that previously failed or was skipped.
	 */
	#[Transition(from: 'pending', to: 'running')]
	#[Transition(from: 'running', to: 'running')]
	#[Transition(from: 'failed', to: 'running')]
	#[Transition(from: 'skipped', to: 'running')]
	public const START = 'start';

	#[Transition(from: 'running', to: 'complete')]
	public const COMPLETE = 'complete';

	#[Transition(from: 'running', to: 'failed')]
	public const FAIL = 'fail';

	#[Transition(from: 'running', to: 'skipped')]
	public const SKIP = 'skip';

	public function start(AssetWorkflowRun $run): void
	{
		$this->apply($run, self::START);
		$run->bumpAttempts();
		$run->clearLastError();
	}

	public function complete(AssetWorkflowRun $run): void
	{
		$this->apply($run, self::COMPLETE);
		$run->clearLastError();
	}

	public function fail(AssetWorkflowRun $run, string $error): void
	{
		$this->apply($run, self::FAIL);
		$run->setLastError(mb_substr($error, 0, 2000));
	}

	public function skip(AssetWorkflowRun $run): void
	{
		$this->apply($run, self::SKIP);
		$run->clearLastError();
	}
}
