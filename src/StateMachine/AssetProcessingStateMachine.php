<?php

declare(strict_types=1);

namespace iikiti\CMS\StateMachine;

use iikiti\CMS\Entity\AbstractDigitalAsset;
use Symfony\Component\Workflow\Attribute\AsWorkflow;
use Symfony\Component\Workflow\Attribute\Transition;
use Symfony\Component\Workflow\WorkflowTrait;

#[AsWorkflow(supports: AbstractDigitalAsset::class, markingProperty: 'processingStatus')]
final class AssetProcessingStateMachine
{
	use WorkflowTrait;

	/**
	 * Kept reachable from every place: the messenger may re-deliver
	 * ProcessDigitalAsset while an asset already sits in processing, complete
	 * or failed, and it must be able to run again exactly as before.
	 */
	#[Transition(from: 'pending', to: 'processing')]
	#[Transition(from: 'processing', to: 'processing')]
	#[Transition(from: 'complete', to: 'processing')]
	#[Transition(from: 'failed', to: 'processing')]
	public const PROCESS = 'process';

	#[Transition(from: 'processing', to: 'complete')]
	public const FINISH = 'complete';

	#[Transition(from: 'processing', to: 'failed')]
	public const FAILURE = 'fail';

	#[Transition(from: 'pending', to: 'pending')]
	#[Transition(from: 'processing', to: 'pending')]
	#[Transition(from: 'complete', to: 'pending')]
	#[Transition(from: 'failed', to: 'pending')]
	public const REQUEUE = 'requeue';

	public function process(AbstractDigitalAsset $asset): void
	{
		$this->apply($asset, self::PROCESS);
	}

	public function complete(AbstractDigitalAsset $asset): void
	{
		$this->apply($asset, self::FINISH);
	}

	public function fail(AbstractDigitalAsset $asset): void
	{
		$this->apply($asset, self::FAILURE);
	}

	public function requeue(AbstractDigitalAsset $asset): void
	{
		$this->apply($asset, self::REQUEUE);
	}
}
