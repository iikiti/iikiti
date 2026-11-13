<?php

namespace iikiti\CMS\Mcp;

use ApiPlatform\JsonSchema\Schema;
use ApiPlatform\JsonSchema\SchemaFactoryInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Catalog of caller-facing API routes plus explicitly registered API functions.
 *
 * Only API routes enter the catalog. MCP transport, authentication, and internal
 * worker callbacks are not callable application APIs and are kept out.
 */
final class McpApiEndpointCatalog
{
	/** @var array<string, McpApiEndpoint>|null */
	private ?array $endpoints = null;

	/**
	 * @param iterable<McpApiToolInterface> $tools
	 */
	public function __construct(
		private readonly RouterInterface $router,
		private readonly PluginRegistry $plugins,
		private readonly ?ResourceMetadataCollectionFactoryInterface $metadataFactory = null,
		private readonly ?SchemaFactoryInterface $schemaFactory = null,
		#[AutowireIterator('iikiti.mcp_api_tool')]
		private readonly iterable $tools = [],
	) {
	}

	/** @return list<McpApiEndpoint> */
	public function all(): array
	{
		$this->endpoints ??= $this->buildEndpoints();

		return array_values(array_filter(
			$this->endpoints,
			fn (McpApiEndpoint $endpoint): bool => $this->isAvailableForCurrentSite($endpoint),
		));
	}

	public function get(string $id): ?McpApiEndpoint
	{
		foreach ($this->all() as $endpoint) {
			if ($endpoint->id === $id) {
				return $endpoint;
			}
		}

		return null;
	}

	/**
	 * Return the full input schema for this endpoint's `api_request.arguments`.
	 *
	 * @return array<string, mixed>
	 */
	public function getInputSchema(McpApiEndpoint $endpoint): array
	{
		if (null !== $endpoint->tool) {
			return $endpoint->tool->getInputSchema();
		}

		$pathProperties = [];
		$requiredPath = [];
		$defaults = $endpoint->routeDefaults;
		$requirements = $endpoint->route?->getRequirements() ?? [];
		foreach ($endpoint->pathVariables as $variable) {
			if ('_format' === $variable) {
				continue;
			}

			$requirement = $requirements[$variable] ?? '';
			$isInteger = is_string($requirement) && (
				str_contains($requirement, '\\d') ||
				str_contains($requirement, '[0-9]') ||
				1 === preg_match('/\bint(?:eger)?\b/i', $requirement)
			);
			$pathProperties[$variable] = [
				'type' => $isInteger ? 'integer' : 'string',
				'description' => sprintf('Value for the %s path parameter in %s.', $variable, $endpoint->path),
			];
			if (!array_key_exists($variable, $defaults)) {
				$requiredPath[] = $variable;
			}
		}

		$parameters = $this->queryParameterSchema($endpoint->operation);
		$bodySchema = null;
		if (in_array($endpoint->method, ['POST', 'PUT', 'PATCH'], true)) {
			if (null === $endpoint->operation || false !== $endpoint->operation->getInput()) {
				$bodySchema = $this->bodySchema($endpoint->operation) ?? [
					'description' => 'JSON body accepted by this custom API route; the route controller performs the authoritative validation.',
					'additionalProperties' => true,
				];
				$bodySchema = $this->ensureSchemaDescriptions($bodySchema, $endpoint->title);
			}
		}
		$definitions = [];
		if (is_array($bodySchema['definitions'] ?? null)) {
			$definitions = array_map(fn (array $definition): array => $this->ensureSchemaDescriptions($definition, $endpoint->title), $bodySchema['definitions']);
			unset($bodySchema['definitions']);
		}
		$properties = [
			'pathParams' => [
				'type' => 'object',
				'description' => 'Values for route placeholders. Use exactly the path parameters listed here.',
				'properties' => (object) $pathProperties,
				'additionalProperties' => false,
			],
			'query' => [
				'type' => 'object',
				'description' => 'Query parameters accepted by this endpoint. See the endpoint-specific parameter schema and existing API filters.',
				'properties' => (object) $parameters['properties'],
				'additionalProperties' => true,
			],
		];
		if ([] !== $requiredPath) {
			$properties['pathParams']['required'] = $requiredPath;
		}
		if (null !== $bodySchema) {
			$properties['body'] = [
				'description' => 'JSON request body accepted by the existing API operation.',
			] + $bodySchema;
		}
		if (in_array($endpoint->method, ['POST', 'PUT', 'PATCH'], true)) {
			$properties['files'] = [
				'type' => 'object',
				'description' => 'Optional multipart uploads for endpoints that accept files. Use the endpoint description to determine whether file fields are supported.',
				'additionalProperties' => [
					'type' => 'object',
					'description' => 'One uploaded file.',
					'properties' => [
						'filename' => ['type' => 'string', 'description' => 'Safe client filename including extension.', 'maxLength' => 255],
						'mimeType' => ['type' => 'string', 'description' => 'Declared media type; the endpoint must verify actual file content.'],
						'contentBase64' => ['type' => 'string', 'description' => 'Base64-encoded file content, bounded by the MCP request size limit.'],
					],
					'required' => ['filename', 'contentBase64'],
					'additionalProperties' => false,
				],
			];
		}

		$schema = [
			'type' => 'object',
			'description' => sprintf('Arguments for the existing %s %s API operation.', $endpoint->method, $endpoint->path),
			'properties' => $properties,
			'additionalProperties' => false,
		];
		if ([] !== $requiredPath) {
			$schema['required'] = ['pathParams'];
		}
		if ([] !== $definitions) {
			$schema['definitions'] = $definitions;
		}

		return $schema;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getOutputSchema(McpApiEndpoint $endpoint): ?array
	{
		if (null !== $endpoint->tool) {
			return $endpoint->tool->getOutputSchema();
		}

		return $this->schemaForOperation($endpoint->operation, input: false);
	}

	/**
	 * @return array<string, bool>
	 */
	public function getAnnotations(McpApiEndpoint $endpoint): array
	{
		if (null !== $endpoint->tool) {
			return $endpoint->tool->getAnnotations();
		}

		return [
			'readOnlyHint' => $endpoint->isReadOnly(),
			'destructiveHint' => 'DELETE' === $endpoint->method,
			'idempotentHint' => in_array($endpoint->method, ['GET', 'PUT', 'DELETE'], true),
			'openWorldHint' => false,
		];
	}

	/** @return array<string, mixed> */
	private function buildEndpoints(): array
	{
		$endpoints = [];
		foreach ($this->router->getRouteCollection() as $routeName => $route) {
			if (!$this->isCallableApiRoute($routeName, $route)) {
				continue;
			}

			$defaults = $route->getDefaults();
			$resourceClass = $defaults['_api_resource_class'] ?? null;
			$controller = $defaults['_controller'] ?? null;
			$pluginSlug = $this->pluginSlugFor(is_string($resourceClass) ? $resourceClass : null, is_string($controller) ? $controller : null);
			$this->assertEndpointOwner($resourceClass, $controller, $pluginSlug, (string) $routeName);
			$operation = $this->resolveOperation($resourceClass, $defaults['_api_operation_name'] ?? null);
			$path = $route->getPath();
			$domain = $this->featureDomain($path, is_string($resourceClass) ? $resourceClass : null, (string) $routeName, $operation);
			$methods = $this->supportedMethods($route);
			if ([] === $methods) {
				continue;
			}
			$variables = array_values(array_filter($route->compile()->getVariables(), static fn (string $name): bool => '_format' !== $name));

			foreach ($methods as $method) {
				$accessPrefix = 'GET' === $method ? 'read_' : 'write_';
				$operationSuffix = $this->operationSuffix((string) $routeName);
				$toolName = $accessPrefix.$domain.'.'.strtolower($method).'.'.$operationSuffix;
				$id = null === $pluginSlug ?
					'iikiti.core.'.$toolName :
					sprintf('iikiti.plugin.%s.%s', $pluginSlug, $toolName);
				if (isset($endpoints[$id])) {
					throw new \LogicException(sprintf('Duplicate MCP API ID "%s" maps to more than one API endpoint.', $id));
				}

				[$title, $description] = $this->describeRoute($routeName, $method, $path, $resourceClass, $operation, $controller);
				$endpoints[$id] = new McpApiEndpoint(
					id: $id,
					title: $title,
					description: $description,
					routeName: (string) $routeName,
					method: $method,
					path: $path,
					pluginSlug: $pluginSlug,
					pathVariables: $variables,
					routeDefaults: $defaults,
					operation: $operation,
					route: $route,
				);
			}
		}

		foreach ($this->tools as $tool) {
			$this->validateTool($tool);
			$pluginSlug = $this->pluginSlugFor($tool::class, $tool::class);
			if (null === $pluginSlug && !str_starts_with($tool::class, 'iikiti\\CMS\\')) {
				throw new \LogicException(sprintf('MCP API tool "%s" must be in the iikiti core or an installed plugin namespace.', $tool::class));
			}
			$prefix = null === $pluginSlug ? 'iikiti.core.' : sprintf('iikiti.plugin.%s.', $pluginSlug);
			$id = $prefix.$tool->getName();
			if (isset($endpoints[$id])) {
				throw new \LogicException(sprintf('Duplicate MCP API ID "%s" is registered.', $id));
			}
			$endpoints[$id] = new McpApiEndpoint(
				id: $id,
				title: $tool->getTitle(),
				description: $tool->getDescription(),
				routeName: '',
				method: 'FUNCTION',
				path: '',
				pluginSlug: $pluginSlug,
				pathVariables: [],
				routeDefaults: [],
				tool: $tool,
			);
		}

		ksort($endpoints, \SORT_STRING);

		return $endpoints;
	}

	private function isCallableApiRoute(string $routeName, Route $route): bool
	{
		$path = $route->getPath();
		if (!str_starts_with($path, '/api/') || '/api/mcp' === $path) {
			return false;
		}

		// Credentials and internal worker callbacks are intentionally not part of
		// the application API catalog; callers must authenticate to MCP first.
		if (str_starts_with($path, '/api/auth/') ||
			str_starts_with($path, '/api/internal/') ||
			str_starts_with($routeName, 'api_internal_') ||
			str_starts_with($routeName, 'api_auth_') ||
			'_api_errors' === $routeName ||
			str_starts_with($routeName, '_api_validation_errors_')) {
			return false;
		}

		return [] !== $this->supportedMethods($route);
	}

	/** @return list<string> */
	private function supportedMethods(Route $route): array
	{
		$methods = $route->getMethods();
		if ([] === $methods) {
			$methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
		}

		return array_values(array_intersect($methods, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']));
	}

	private function isAvailableForCurrentSite(McpApiEndpoint $endpoint): bool
	{
		if (null === $endpoint->pluginSlug) {
			return true;
		}

		return SiteRegistry::hasCurrent() &&
			in_array($endpoint->pluginSlug, SiteRegistry::getCurrent()->getEnabledPlugins(), true);
	}

	private function pluginSlugFor(?string $resourceClass, ?string $controller): ?string
	{
		foreach ($this->plugins->getManifests() as $manifest) {
			foreach ([$resourceClass, $controller] as $className) {
				if (null === $className || '' === $className) {
					continue;
				}
				$className = strstr($className, '::', true) ?: $className;
				if (str_starts_with($className, $manifest->namespace.'\\')) {
					return $manifest->slug;
				}
			}
		}

		return null;
	}

	private function assertEndpointOwner(mixed $resourceClass, mixed $controller, ?string $pluginSlug, string $routeName): void
	{
		if (null !== $pluginSlug) {
			return;
		}

		$ownerClass = is_string($resourceClass) && '' !== $resourceClass ? $resourceClass : $controller;
		if (is_string($ownerClass)) {
			$ownerClass = strstr($ownerClass, '::', true) ?: $ownerClass;
			if (class_exists($ownerClass) && str_contains($ownerClass, '\\') && !str_starts_with($ownerClass, 'iikiti\\CMS\\')) {
				throw new \LogicException(sprintf('API route "%s" belongs to an unregistered namespace "%s"; register it as an iikiti plugin API.', $routeName, $ownerClass));
			}
		}
	}

	private function resolveOperation(mixed $resourceClass, mixed $operationName): ?Operation
	{
		if (!is_string($resourceClass) || '' === $resourceClass || !is_string($operationName) || '' === $operationName || null === $this->metadataFactory) {
			return null;
		}

		try {
			return $this->metadataFactory->create($resourceClass)->getOperation($operationName, httpOperation: true);
		} catch (\Throwable) {
			return null;
		}
	}

	private function featureDomain(string $path, ?string $resourceClass, string $routeName, ?Operation $operation): string
	{
		$pathSegments = explode('/', trim($path, '/'));
		if ('api' === strtolower($pathSegments[0])) {
			array_shift($pathSegments);
		}
		if (in_array(strtolower($pathSegments[0]), ['admin', 'public', 'editor', 'user', 'site'], true)) {
			array_shift($pathSegments);
		}
		$resourceSegment = $pathSegments[0] ?? '';
		$resourceShortName = is_string($resourceClass) ? basename(str_replace('\\', '/', $resourceClass)) : '';
		$sources = array_map('strtolower', [$resourceSegment, $resourceShortName, $routeName, $operation?->getShortName() ?? '']);
		$domainPatterns = [
			'site_settings' => ['site.?settings?', 'sites?/[^ ]*/settings'],
			'objects' => ['db.?objects?', 'object'],
			'groups' => ['groups?', 'memberships?'],
			'roles' => ['roles?'],
			'permissions' => ['permissions?'],
			'activity' => ['activity', 'audit', 'event.?logs?'],
			'workflows' => ['workflows?', 'workflow.?tasks?', 'transitions?'],
			'assets' => ['assets?', 'digital.?assets?'],
			'transfers' => ['transfers?', 'imports?', 'exports?'],
			'jobs' => ['batch(?:es)?', 'jobs?', 'schedules?', 'scheduler'],
			'localization' => ['locales?', 'localization', 'translations?'],
			'forms' => ['forms?'],
			'plugins' => ['plugins?'],
			'search' => ['search', 'indexes?'],
			'security' => ['security', 'firewall', 'polic(y|ies)'],
			'settings' => ['settings?'],
			'templates' => ['templates?'],
			'layouts' => ['layouts?'],
			'shells' => ['shells?'],
			'blocks' => ['blocks?', 'widgets?'],
			'applications' => ['applications?', 'applicationresource'],
			'sites' => ['sites?', 'siteresource'],
			'users' => ['users?', 'userresource'],
		];
		foreach ($domainPatterns as $domain => $patterns) {
			foreach ($sources as $source) {
				foreach ($patterns as $pattern) {
					if (preg_match('~(?:^|[^a-z])'.$pattern.'(?:$|[^a-z])~i', $source)) {
						return $domain;
					}
				}
			}
		}

		return $this->operationSuffix($routeName) ?: 'api';
	}

	private function operationSuffix(string $routeName): string
	{
		$suffix = strtolower(trim((string) preg_replace('/[^a-z0-9_-]+/i', '_', $routeName), '_-'));

		return '' !== $suffix ? $suffix : 'operation';
	}

	/** @return array{string, string} */
	private function describeRoute(string $routeName, string $method, string $path, mixed $resourceClass, ?Operation $operation, mixed $controller): array
	{
		$title = ucwords(str_replace(['_', '-', '.'], ' ', $routeName));
		if ('' === trim($title)) {
			$title = $operation?->getShortName() ?: 'API operation';
		}

		$description = $operation?->getDescription();
		if (!is_string($description) || '' === trim($description)) {
			$resourceName = is_string($resourceClass) ? basename(str_replace('\\', '/', $resourceClass)) : 'API';
			$handlerDescription = $this->controllerDescription($controller);
			$purpose = is_string($handlerDescription) && '' !== $handlerDescription ?
				$handlerDescription :
				sprintf('%s the %s API operation for %s.', 'GET' === $method ? 'Read data with' : 'Perform', $title, $resourceName);
			$description = sprintf('%s It maps to %s %s. Supply only the documented path, query, and JSON body values; existing API validation, authorization, site, and audit rules apply.', $purpose, $method, $path);
		}

		return [$title, trim($description)];
	}

	private function controllerDescription(mixed $controller): ?string
	{
		if (!is_string($controller) || '' === $controller) {
			return null;
		}
		[$className, $methodName] = array_pad(explode('::', $controller, 2), 2, null);
		if (!class_exists($className)) {
			return null;
		}

		try {
			$reflection = is_string($methodName) && method_exists($className, $methodName) ?
				new \ReflectionMethod($className, $methodName) :
				new \ReflectionClass($className);
			$comment = $reflection->getDocComment();
			if (false === $comment) {
				return null;
			}
			$lines = preg_split('/\R/', $comment) ?: [];
			$descriptionLines = [];
			foreach ($lines as $line) {
				$line = trim(preg_replace('/^\s*\/?\*+\s?/', '', $line) ?? '');
				if ('' === $line || str_starts_with($line, '@')) {
					if ([] !== $descriptionLines) {
						break;
					}
					continue;
				}
				$descriptionLines[] = $line;
			}
			$description = trim(implode(' ', $descriptionLines));

			return '' !== $description ? $description : null;
		} catch (\ReflectionException) {
			return null;
		}
	}

	private function validateTool(McpApiToolInterface $tool): void
	{
		if ('' === trim($tool->getTitle()) || '' === trim($tool->getDescription())) {
			throw new \LogicException(sprintf('MCP API tool "%s" must define a title and description.', $tool::class));
		}
		if ('' === trim($tool->getRequiredPermission())) {
			throw new \LogicException(sprintf('MCP API tool "%s" must declare a required permission.', $tool::class));
		}
		$name = $tool->getName();
		if (1 !== preg_match('/^[a-z][a-z0-9_.-]*$/', $name)) {
			throw new \LogicException(sprintf('MCP API tool "%s" has an invalid local name "%s".', $tool::class, $name));
		}
		$schema = $tool->getInputSchema();
		if ('object' !== ($schema['type'] ?? null) || !is_array($schema['properties'] ?? null)) {
			throw new \LogicException(sprintf('MCP API tool "%s" must provide an object input schema.', $tool::class));
		}
		$this->validateSchemaDescriptions($schema, $tool::class, 'input');
	}

	/** @param array<string, mixed> $schema */
	private function validateSchemaDescriptions(array $schema, string $toolClass, string $path): void
	{
		$properties = $schema['properties'] ?? [];
		if (is_array($properties)) {
			foreach ($properties as $property => $propertySchema) {
				if (!is_array($propertySchema) || !is_string($propertySchema['description'] ?? null) || '' === trim($propertySchema['description'])) {
					throw new \LogicException(sprintf('MCP API tool "%s" schema property "%s.%s" must have a description.', $toolClass, $path, $property));
				}
				$this->validateSchemaDescriptions($propertySchema, $toolClass, $path.'.'.$property);
			}
		}

		foreach (['items', 'additionalProperties'] as $schemaKey) {
			if (is_array($schema[$schemaKey] ?? null)) {
				$this->validateSchemaDescriptions($schema[$schemaKey], $toolClass, $path.'.'.$schemaKey);
			}
		}
	}

	/**
	 * @param array<string, mixed> $schema
	 *
	 * @return array<string, mixed>
	 */
	private function ensureSchemaDescriptions(array $schema, string $context): array
	{
		$properties = $schema['properties'] ?? null;
		if (is_array($properties)) {
			foreach ($properties as $name => $propertySchema) {
				if (!is_array($propertySchema)) {
					continue;
				}
				if (!is_string($propertySchema['description'] ?? null) || '' === trim($propertySchema['description'])) {
					$propertySchema['description'] = sprintf('Value for "%s" on the %s API operation.', $name, $context);
				}
				$schema['properties'][$name] = $this->ensureSchemaDescriptions($propertySchema, $context);
			}
		}

		foreach (['items', 'additionalProperties'] as $schemaKey) {
			if (is_array($schema[$schemaKey] ?? null)) {
				$schema[$schemaKey] = $this->ensureSchemaDescriptions($schema[$schemaKey], $context);
			}
		}

		return $schema;
	}

	/** @return array{properties: array<string, mixed>} */
	private function queryParameterSchema(?Operation $operation): array
	{
		$properties = [];
		foreach ($operation?->getParameters() ?? [] as $name => $parameter) {
			$parameterName = $parameter->getKey() ?? $name;
			if ('' === $parameterName) {
				continue;
			}
			$parameterSchema = $parameter->getSchema();
			if (!is_array($parameterSchema)) {
				$parameterSchema = ['type' => 'string'];
			}
			$parameterDescription = $parameter->getDescription();
			$parameterSchema['description'] = is_string($parameterDescription) ?
				$parameterDescription :
				sprintf('Query parameter "%s" for this API operation.', $parameterName);
			$properties[$parameterName] = $this->ensureSchemaDescriptions($parameterSchema, $operation?->getShortName() ?? 'API');
		}

		return ['properties' => $properties];
	}

	/** @return array<string, mixed>|null */
	private function bodySchema(?Operation $operation): ?array
	{
		return $this->schemaForOperation($operation, input: true);
	}

	/** @return array<string, mixed>|null */
	private function schemaForOperation(?Operation $operation, bool $input): ?array
	{
		if (null === $operation || null === $this->schemaFactory) {
			return null;
		}

		$io = $input ? $operation->getInput() : $operation->getOutput();
		if (false === $io) {
			return null;
		}
		$className = is_string($io) ? $io : (is_array($io) && is_string($io['class'] ?? null) ? $io['class'] : null);
		$className ??= $operation->getClass();
		if (!is_string($className) || !class_exists($className)) {
			return null;
		}

		try {
			$schema = $this->schemaFactory->buildSchema($className, 'json', $input ? Schema::TYPE_INPUT : Schema::TYPE_OUTPUT, $operation);

			return $schema->getArrayCopy();
		} catch (\Throwable) {
			return null;
		}
	}
}
