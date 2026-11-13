<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Mcp;

use iikiti\CMS\Mcp\ApiTools;
use iikiti\CMS\Mcp\McpApiEndpoint;
use iikiti\CMS\Mcp\McpApiEndpointCatalog;
use iikiti\CMS\Mcp\McpApiRequestExecutor;
use iikiti\CMS\Mcp\McpApiToolInterface;
use iikiti\CMS\Plugin\PluginRegistry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class McpApiEndpointCatalogTest extends TestCase
{
	public function testAllApiMethodsArePaginatedBehindTheThreeWrapperTools(): void
	{
		$catalog = $this->createCatalog([
			'admin_users_list' => new Route('/api/admin/users', ['_controller' => 'TestController::list'], [], [], '', [], ['GET']),
			'admin_users_create' => new Route('/api/admin/users', ['_controller' => 'TestController::create'], [], [], '', [], ['POST']),
			'api_auth_login' => new Route('/api/auth/login', ['_controller' => 'TestController::login'], [], [], '', [], ['POST']),
			'api_internal_job' => new Route('/api/internal/job', ['_controller' => 'TestController::run'], [], [], '', [], ['POST']),
		]);
		$tools = $this->createApiTools($catalog);

		$firstPage = $tools->apiList(limit: 1);
		self::assertFalse($firstPage->isError);
		self::assertSame(1, $firstPage->structuredContent['limit']);
		self::assertSame(2, $firstPage->structuredContent['total']);
		self::assertNotNull($firstPage->structuredContent['nextCursor']);

		$secondPage = $tools->apiList(cursor: $firstPage->structuredContent['nextCursor'], limit: 1);
		self::assertFalse($secondPage->isError);
		self::assertCount(1, $secondPage->structuredContent['items']);
		self::assertNull($secondPage->structuredContent['nextCursor']);
		self::assertNotSame($firstPage->structuredContent['items'][0]['id'], $secondPage->structuredContent['items'][0]['id']);
	}

	public function testListDefaultsToFiftyAndRejectsAnOutOfRangeLimit(): void
	{
		$routes = [];
		for ($index = 0; $index < 60; ++$index) {
			$routes['api_objects_'.$index] = new Route('/api/objects/'.$index, ['_controller' => 'TestController::read'], [], [], '', [], ['GET']);
		}
		$tools = $this->createApiTools($this->createCatalog($routes));

		$page = $tools->apiList();
		self::assertSame(50, $page->structuredContent['limit']);
		self::assertCount(50, $page->structuredContent['items']);
		self::assertNotNull($page->structuredContent['nextCursor']);

		$invalid = $tools->apiList(limit: 201);
		self::assertTrue($invalid->isError);
	}

	public function testUnrestrictedApiRoutesExposeEverySupportedHttpMethod(): void
	{
		$catalog = $this->createCatalog([
			'api_operations' => new Route('/api/operations', ['_controller' => 'TestController::operate']),
		]);

		$methods = array_map(static fn (McpApiEndpoint $endpoint): string => $endpoint->method, $catalog->all());
		sort($methods);

		self::assertSame(['DELETE', 'GET', 'PATCH', 'POST', 'PUT'], $methods);
	}

	public function testDescribeIncludesRouteArgumentSchemaAndRequiredDescriptions(): void
	{
		$catalog = $this->createCatalog([
			'admin_user_get' => new Route('/api/admin/users/{id}', ['_controller' => 'TestController::get'], ['id' => '\\d+'], [], '', [], ['GET']),
		]);
		$tools = $this->createApiTools($catalog);
		$list = $tools->apiList();
		$apiId = $list->structuredContent['items'][0]['id'];
		$description = $tools->apiDescribe($apiId);
		$descriptionData = json_decode(json_encode($description->structuredContent, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

		self::assertFalse($description->isError);
		self::assertSame('integer', $descriptionData['inputSchema']['properties']['pathParams']['properties']['id']['type']);
		self::assertNotEmpty($descriptionData['inputSchema']['properties']['pathParams']['properties']['id']['description']);
		self::assertNotEmpty($descriptionData['description']);
	}

	public function testOnlyThreeNamedMcpToolsAreDeclaredAndEveryDescriptionIsRequired(): void
	{
		$reflection = new \ReflectionClass(ApiTools::class);
		$names = [];
		foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			$attributes = $method->getAttributes(McpTool::class);
			if ([] === $attributes) {
				continue;
			}
			$tool = $attributes[0]->newInstance();
			$names[] = $tool->name;
			self::assertNotEmpty($tool->description);
		}

		self::assertSame(['iikiti.api_list', 'iikiti.api_describe', 'iikiti.api_request'], $names);
	}

	public function testInternalArgumentsAreValidatedAgainstTheDescribedSchema(): void
	{
		$validator = new SchemaValidator();
		$schema = [
			'type' => 'object',
			'properties' => ['pathParams' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'Object identifier.']], 'required' => ['id'], 'additionalProperties' => false]],
			'required' => ['pathParams'],
			'additionalProperties' => false,
		];

		self::assertSame([], $validator->validateAgainstJsonSchema(['pathParams' => ['id' => 3]], $schema));
		self::assertNotEmpty($validator->validateAgainstJsonSchema(['pathParams' => ['id' => 'wrong']], $schema));
	}

	public function testCustomFunctionRequiresItsDeclaredPermission(): void
	{
		$tool = new class implements McpApiToolInterface {
			public bool $executed = false;

			public function getName(): string
			{
				return 'read_private_data';
			}

			public function getTitle(): string
			{
				return 'Read private data';
			}

			public function getDescription(): string
			{
				return 'Read private data after the declared permission is granted.';
			}

			public function getRequiredPermission(): string
			{
				return 'ROLE_PRIVATE_DATA';
			}

			public function getInputSchema(): array
			{
				return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
			}

			public function getOutputSchema(): ?array
			{
				return null;
			}

			public function getAnnotations(): array
			{
				return ['readOnlyHint' => true];
			}

			public function execute(array $arguments): mixed
			{
				$this->executed = true;

				return ['ok' => true];
			}
		};
		$catalog = $this->createCatalog([], [$tool]);
		$executor = $this->createExecutor($catalog, false);

		$result = $executor->execute('iikiti.core.read_private_data', []);

		self::assertTrue($result->isError);
		self::assertFalse($tool->executed);
	}

	/**
	 * @param array<string, Route>      $routes
	 * @param list<McpApiToolInterface> $tools
	 */
	private function createCatalog(array $routes, array $tools = []): McpApiEndpointCatalog
	{
		$collection = new RouteCollection();
		foreach ($routes as $name => $route) {
			$collection->add($name, $route);
		}
		$router = $this->createStub(RouterInterface::class);
		$router->method('getRouteCollection')->willReturn($collection);

		return new McpApiEndpointCatalog($router, new PluginRegistry(), tools: $tools);
	}

	private function createApiTools(McpApiEndpointCatalog $catalog): ApiTools
	{
		$executor = $this->createExecutor($catalog);

		return new ApiTools($catalog, $executor);
	}

	private function createExecutor(McpApiEndpointCatalog $catalog, bool $isGranted = true): McpApiRequestExecutor
	{
		$router = $this->createStub(RouterInterface::class);
		$kernel = $this->createStub(KernelInterface::class);
		$authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
		$authorizationChecker->method('isGranted')->willReturn($isGranted);

		return new McpApiRequestExecutor($catalog, $router, $kernel, new RequestStack(), $authorizationChecker, new NullLogger(), 1_048_576, 131_072);
	}
}
