<?php

namespace iikiti\CMS\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The small, stable MCP surface. Application APIs are discovered and called
 * through these three wrappers rather than exposed as thousands of MCP tools.
 */
final class ApiTools
{
	public function __construct(
		private readonly McpApiEndpointCatalog $catalog,
		private readonly McpApiRequestExecutor $executor,
		private readonly ?McpApiRateLimiter $rateLimiter = null,
		#[Autowire('%mcp_api_max_page_size%')]
		private readonly int $maxPageSize = 200,
	) {
		if ($maxPageSize < 1 || $maxPageSize > 200) {
			throw new \InvalidArgumentException('The MCP API page-size cap must be between 1 and 200.');
		}
	}

	#[McpTool(
		name: 'iikiti.api_list',
		title: 'List iikiti APIs',
		description: 'Discover caller-facing iikiti core and enabled-plugin API endpoints and functions. Use this first to find exact API IDs; then use iikiti.api_describe for the full input schema and iikiti.api_request to invoke an ID. Results are cursor-paginated. The limit defaults to 50 entries per page and you can lower or raise it from 1 to 200; the server may enforce a lower configured cap. Listing an endpoint does not grant permission to call it. This does not change MCP tools/list, which contains only these three wrapper tools.',
		annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
	)]
	#[Schema(definition: [
		'type' => 'object',
		'properties' => [
			'namespace' => ['type' => 'string', 'description' => 'Optional namespace prefix, such as iikiti.core or iikiti.plugin.example.'],
			'query' => ['type' => 'string', 'description' => 'Optional case-insensitive search across API IDs, titles, and descriptions.'],
			'cursor' => ['type' => 'string', 'description' => 'Opaque nextCursor returned by the previous page; omit for the first page.'],
			'limit' => ['type' => 'integer', 'description' => 'Results per page. Defaults to 50; choose 1–200 to decrease or increase the page size.', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
		],
		'additionalProperties' => false,
	])]
	public function apiList(?string $namespace = null, ?string $query = null, ?string $cursor = null, int $limit = 50): CallToolResult
	{
		if (null !== $limited = $this->rateLimitError()) {
			return $limited;
		}
		if ($limit < 1 || $limit > min(200, $this->maxPageSize)) {
			return $this->error(sprintf('The limit must be between 1 and %d.', min(200, $this->maxPageSize)));
		}

		$items = $this->catalog->all();
		if (null !== $namespace && '' !== trim($namespace)) {
			$prefix = rtrim(trim($namespace), '.').'.';
			$items = array_values(array_filter($items, static fn (McpApiEndpoint $endpoint): bool => str_starts_with($endpoint->id, $prefix)));
		}
		if (null !== $query && '' !== trim($query)) {
			$needle = mb_strtolower(trim($query));
			$items = array_values(array_filter($items, static fn (McpApiEndpoint $endpoint): bool => str_contains(mb_strtolower($endpoint->id.' '.$endpoint->title.' '.$endpoint->description), $needle)));
		}

		$offset = 0;
		if (null !== $cursor && '' !== $cursor) {
			$cursorId = $this->decodeCursor($cursor);
			if (null === $cursorId) {
				return $this->error('The pagination cursor is invalid.');
			}
			$lastIndex = null;
			foreach ($items as $index => $endpoint) {
				if ($endpoint->id === $cursorId) {
					$lastIndex = $index;
					break;
				}
			}
			if (null === $lastIndex) {
				return $this->error('The pagination cursor does not match this filtered API catalog. Restart from the first page.');
			}
			$offset = $lastIndex + 1;
		}

		$page = array_slice($items, $offset, $limit);
		$nextCursor = null;
		if ($offset + count($page) < count($items) && [] !== $page) {
			$nextCursor = $this->encodeCursor($page[array_key_last($page)]->id);
		}

		$result = [
			'items' => array_map(fn (McpApiEndpoint $endpoint): array => [
				'id' => $endpoint->id,
				'title' => $endpoint->title,
				'description' => $endpoint->description,
				'method' => $endpoint->method,
				'path' => $endpoint->path,
				'annotations' => $this->catalog->getAnnotations($endpoint),
			], $page),
			'limit' => $limit,
			'total' => count($items),
			'nextCursor' => $nextCursor,
		];

		return $this->success($result);
	}

	#[McpTool(
		name: 'iikiti.api_describe',
		title: 'Describe an iikiti API',
		description: 'Return the complete contract for one exact API ID from iikiti.api_list: purpose, HTTP method and route or function, path/query/body argument schema, output schema where available, and read/write annotations. Use this before iikiti.api_request when the required arguments or effects are not already clear. Describing an API does not authorize its execution; the existing API security rules still apply.',
		annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
	)]
	#[Schema(definition: [
		'type' => 'object',
		'properties' => [
			'apiId' => ['type' => 'string', 'description' => 'Exact API ID returned by iikiti.api_list.'],
		],
		'required' => ['apiId'],
		'additionalProperties' => false,
	])]
	public function apiDescribe(string $apiId): CallToolResult
	{
		if (null !== $limited = $this->rateLimitError()) {
			return $limited;
		}
		$endpoint = $this->catalog->get($apiId);
		if (null === $endpoint) {
			return $this->error('Unknown or unavailable API identifier. Use api_list to discover available APIs.');
		}

		$description = [
			'id' => $endpoint->id,
			'title' => $endpoint->title,
			'description' => $endpoint->description,
			'kind' => null === $endpoint->tool ? 'http_endpoint' : 'business_function',
			'method' => $endpoint->method,
			'path' => $endpoint->path,
			'inputSchema' => $this->catalog->getInputSchema($endpoint),
			'outputSchema' => $this->catalog->getOutputSchema($endpoint),
			'annotations' => $this->catalog->getAnnotations($endpoint),
			'authorization' => null !== $endpoint->tool ?
				sprintf('Requires permission "%s" and the plugin must be enabled for the current site. The handler must also enforce existing business rules and audit consequential changes.', $endpoint->tool->getRequiredPermission()) :
				'Execution is performed by the existing API route and is subject to its current authentication, authorization, site, plugin, validation, and audit rules.',
		];

		return $this->success($description);
	}

	/** @param array<string, mixed> $arguments */
	#[McpTool(
		name: 'iikiti.api_request',
		title: 'Call an iikiti API',
		description: 'Invoke one exact iikiti API ID from iikiti.api_list. Call iikiti.api_describe first if you need its input schema. Pass endpoint-specific pathParams, query, body, and (for supported upload routes) bounded base64 files under arguments. This dispatches existing API routes/functions, never an arbitrary URL, PHP service ID, SQL, shell command, or code executor. Existing API authentication, per-operation authorization, site context, validation, and audit behavior remain in force. Treat write calls as consequential; inspect the target and required fields before invoking them.',
		annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: false),
	)]
	#[Schema(definition: [
		'type' => 'object',
		'properties' => [
			'apiId' => ['type' => 'string', 'description' => 'Exact API ID returned by iikiti.api_list.'],
			'arguments' => [
				'type' => 'object',
				'description' => 'Endpoint-specific arguments matching the inputSchema returned by iikiti.api_describe. Route endpoints use pathParams, query, and body as applicable; custom functions use their declared named inputs.',
				'properties' => [
					'pathParams' => ['type' => 'object', 'description' => 'Route placeholder values for an HTTP API endpoint.', 'additionalProperties' => true],
					'query' => ['type' => 'object', 'description' => 'Query parameter values for an HTTP API endpoint.', 'additionalProperties' => true],
					'body' => ['description' => 'JSON body for an HTTP API endpoint; api_describe gives its endpoint-specific schema.'],
					'files' => [
						'type' => 'object',
						'description' => 'Optional named base64 uploads for API endpoints that accept files.',
						'additionalProperties' => [
							'type' => 'object',
							'description' => 'One bounded file upload.',
							'properties' => [
								'filename' => ['type' => 'string', 'description' => 'Client filename including extension.', 'maxLength' => 255],
								'mimeType' => ['type' => 'string', 'description' => 'Declared media type; actual content must be validated by the API endpoint.'],
								'contentBase64' => ['type' => 'string', 'description' => 'Base64 file bytes bounded by the request size limit.'],
							],
							'required' => ['filename', 'contentBase64'],
							'additionalProperties' => false,
						],
					],
				],
				'additionalProperties' => true,
			],
		],
		'required' => ['apiId', 'arguments'],
		'additionalProperties' => false,
	])]
	public function apiRequest(string $apiId, array $arguments): CallToolResult
	{
		if (null !== $limited = $this->rateLimitError()) {
			return $limited;
		}

		return $this->executor->execute($apiId, $arguments);
	}

	private function rateLimitError(): ?CallToolResult
	{
		if (null === $this->rateLimiter) {
			return null;
		}
		$retryAfter = $this->rateLimiter->consume();
		if (null === $retryAfter) {
			return null;
		}

		return $this->error(sprintf('MCP API request limit reached. Retry after %d seconds.', $retryAfter));
	}

	/** @param array<string, mixed> $data */
	private function success(array $data): CallToolResult
	{
		return new CallToolResult([
			new TextContent(json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE)),
		], structuredContent: $data);
	}

	private function error(string $message): CallToolResult
	{
		return CallToolResult::error([new TextContent(json_encode(['error' => $message], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))]);
	}

	private function encodeCursor(string $apiId): string
	{
		return rtrim(strtr(base64_encode($apiId), '+/', '-_'), '=');
	}

	private function decodeCursor(string $cursor): ?string
	{
		$decoded = base64_decode(strtr($cursor, '-_', '+/').str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);

		return false !== $decoded && '' !== $decoded ? $decoded : null;
	}
}
