<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Definition;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('workflow.definition_provider')]
interface WorkflowDefinitionProviderInterface
{
	/** Return the plugin manifest ID that owns the definitions. */
	public function getOwnerKey(): string;

	/** Return the plugin package version used to generate definitions. */
	public function getSourceVersion(): string;

	/** @return iterable<WorkflowDefinitionData> */
	public function getDefinitions(): iterable;
}
