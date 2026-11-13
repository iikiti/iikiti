<?php

namespace iikiti\CMS\Mcp;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A callable API function which is not already represented by an HTTP route.
 *
 * Implementations are registered in the shared MCP API catalog. They never
 * create their own MCP server or transport endpoint.
 */
#[AutoconfigureTag('iikiti.mcp_api_tool')]
interface McpApiToolInterface
{
	/** Stable local name, prefixed by iikiti.core or the owning plugin namespace. */
	public function getName(): string;

	public function getTitle(): string;

	/** Purpose, appropriate use, limits, required context, and side effects. */
	public function getDescription(): string;

	/** Permission attribute/role checked before the function is executed. */
	public function getRequiredPermission(): string;

	/** @return array<string, mixed> JSON Schema object with described properties */
	public function getInputSchema(): array;

	/** @return array<string, mixed>|null */
	public function getOutputSchema(): ?array;

	/** @return array<string, bool> */
	public function getAnnotations(): array;

	/** @param array<string, mixed> $arguments */
	public function execute(array $arguments): mixed;
}
