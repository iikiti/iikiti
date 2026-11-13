<?php

namespace iikiti\CMS\Mcp;

use ApiPlatform\Metadata\Operation;
use Symfony\Component\Routing\Route;

/**
 * Immutable description of an existing API route exposed through MCP.
 */
final readonly class McpApiEndpoint
{
	/**
	 * @param list<string>         $pathVariables
	 * @param array<string, mixed> $routeDefaults
	 */
	public function __construct(
		public string $id,
		public string $title,
		public string $description,
		public string $routeName,
		public string $method,
		public string $path,
		public ?string $pluginSlug,
		public array $pathVariables,
		public array $routeDefaults,
		public ?Operation $operation = null,
		public ?Route $route = null,
		public ?McpApiToolInterface $tool = null,
	) {
	}

	public function isReadOnly(): bool
	{
		if (null !== $this->tool) {
			return $this->tool->getAnnotations()['readOnlyHint'] ?? false;
		}

		return 'GET' === $this->method;
	}
}
