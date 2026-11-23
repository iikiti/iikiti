<?php

declare(strict_types=1);

namespace iikiti\Extension\Forms\Workflow;

use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Workflow\FlowProviderInterface;

final class MultiStepFlowProvider implements FlowProviderInterface
{
	public function __construct(
		private readonly ?PluginActivationChecker $activationChecker = null,
	) {
	}

	#[\Override]
	public function getFlows(): array
	{
		if (null !== $this->activationChecker && !$this->activationChecker->isActive('iikiti-forms')) {
			return [];
		}

		return [
			'multistep' => 'multistep_form',
		];
	}
}
