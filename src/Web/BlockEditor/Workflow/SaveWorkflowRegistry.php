<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Workflow;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Selects the active save workflow. Core default: `draft`
 * ({@see DraftPublishWorkflow}). Plugins register alternatives via the
 * `iikiti.cms.save_workflow` tag.
 */
final class SaveWorkflowRegistry
{
	/**
	 * @param iterable<SaveWorkflowInterface> $workflows
	 */
	public function __construct(
		#[AutowireIterator('iikiti.cms.save_workflow')]
		private readonly iterable $workflows = [],
	) {
	}

	public function default(): SaveWorkflowInterface
	{
		foreach ($this->workflows as $workflow) {
			if ('draft' === $workflow->getName()) {
				return $workflow;
			}
		}

		// Fall back to the first registered workflow (there is always >= 1: core).
		foreach ($this->workflows as $workflow) {
			return $workflow;
		}

		throw new \LogicException('No save workflow is registered.');
	}
}
