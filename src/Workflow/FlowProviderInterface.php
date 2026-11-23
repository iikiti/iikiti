<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Registers interactive session-based workflow flows exposed through
 * `/flow/{flow}/step`.
 */
#[AutoconfigureTag('iikiti.workflow.flow_provider')]
interface FlowProviderInterface
{
	/**
	 * Map of public flow slug => session storage key.
	 *
	 * @return array<string, string>
	 */
	public function getFlows(): array;
}
